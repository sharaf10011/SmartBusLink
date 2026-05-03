<?php
session_start();
include('../includes/session.php');
include('../includes/db_connect.php');

// Check if the user is logged in and if the user is an admin
if (empty($_SESSION['loggedin']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
    header("Location: login.html");
    exit;
}

// Get feedback from the database
$query = "SELECT * FROM feedback";
$result = mysqli_query($conn, $query);

// Filter by date range if the form is submitted
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['filter_feedback'])) {
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];

    $query = "SELECT * FROM feedback WHERE feedback_date BETWEEN '$start_date' AND '$end_date'";
    $result = mysqli_query($conn, $query);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Feedback</title>
    <link rel="stylesheet" href="assets/css/styles.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
</head>

<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="index.html">Care Compass Hospitals</a>
            <a href="dashboard.php" class="btn btn-danger">Back Dashboard</a>
        </div>
    </nav>

    <div class="container mt-5">
        <div class="row">
            <div class="col-md-12 text-center mb-5">
                <h1>View Patient Feedback</h1>
                <p class="lead">Here you can view and filter feedback submitted by patients.</p>
            </div>
        </div>

        <!-- Filter Feedback by Date -->
        <div class="row">
            <div class="col-md-12">
                <h4>Filter Feedback by Date</h4>
                <form action="view_feedback.php" method="POST">
                    <div class="mb-3">
                        <label for="start_date" class="form-label">Start Date</label>
                        <input type="date" class="form-control" id="start_date" name="start_date" required>
                    </div>
                    <div class="mb-3">
                        <label for="end_date" class="form-label">End Date</label>
                        <input type="date" class="form-control" id="end_date" name="end_date" required>
                    </div>
                    <button type="submit" class="btn btn-primary" name="filter_feedback">Filter Feedback</button>
                </form>
            </div>
        </div>

        <!-- Feedback Table -->
        <div class="row mt-5">
            <div class="col-md-12">
                <h4>Patient Feedback</h4>
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Patient Name</th>
                            <th>Rating</th>
                            <th>Comments</th>
                            <th>Date Submitted</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result && mysqli_num_rows($result) > 0): ?>
                            <?php while ($row = mysqli_fetch_assoc($result)): ?>
                                <tr>
                                    <td><?php echo $row['feedback_id']; ?></td>
                                    <td><?php echo htmlspecialchars($row['patient_name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['rating']); ?></td>
                                    <td><?php echo htmlspecialchars($row['comments']); ?></td>
                                    <td><?php echo htmlspecialchars($row['feedback_date']); ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="5">No feedback found for the selected date range.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <footer class="bg-dark text-white text-center py-3 mt-5">
        <p>&copy; 2025 Care Compass Hospitals | All Rights Reserved</p>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>
