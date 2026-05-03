<?php
session_start();

// Check if the user is logged in
if (!isset($_SESSION['loggedin'])) {
    header("Location: login.html");
    exit;
}

$user_type = $_SESSION['user_type'];

// Ensure only patients can access this page
if ($user_type !== 'patient') {
    header("Location: login.html");
    exit;
}

// Fetch patient queries from the database
include('../includes/db_connect.php');
$patient_id = $_SESSION['user_id']; // Assuming user_id is stored in session

// Get the queries submitted by the patient
$query = "SELECT * FROM queries WHERE patient_id = '$patient_id' ORDER BY query_date DESC";
$result = mysqli_query($conn, $query);

// Handle query submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_query'])) {
    $query_subject = $_POST['query_subject'];
    $query_description = $_POST['query_description'];
    $patient_id = $_SESSION['user_id']; // Assuming user_id is stored in session
    $query_date = date("Y-m-d H:i:s");

    // Insert the new query into the database
    $submit_query = $conn->prepare("INSERT INTO queries (patient_id, patient_name, query_subject, query_description, date_submitted) VALUES (?, ?, ?, ?, ?)");
    $submit_query->bind_param("issss", $patient_id, $_SESSION['patient_name'], $query_subject, $query_description, $query_date);

    if ($submit_query->execute()) {
        $message = "Query submitted successfully!";
    } else {
        $message = "Error submitting query: " . $submit_query->error;
    }
}


?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submit Queries</title>
    <link rel="stylesheet" href="assets/css/styles.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body>
    <!-- Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="index.html">Care Compass Hospitals</a>
            <a href="dashboard.php" class="btn btn-danger">Back to Dashboard</a>
        </div>
    </nav>

    <!-- Queries Section -->
    <div class="container mt-5">
        <h1 class="text-center">Your Queries</h1>

        <?php if (isset($message)): ?>
            <div class="alert alert-info"><?php echo $message; ?></div>
        <?php endif; ?>

        <!-- Patient Queries Table -->
        <div class="row">
            <div class="col-md-12">
                <h4>Previous Queries</h4>
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Query Subject</th>
                            <th>Query Description</th>
                            <th>Date Submitted</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (mysqli_num_rows($result) > 0): ?>
                            <?php while ($row = mysqli_fetch_assoc($result)): ?>
                                <tr>
                                    <td><?php echo $row['query_id']; ?></td>
                                    <td><?php echo htmlspecialchars($row['query_subject']); ?></td>
                                    <td><?php echo htmlspecialchars($row['query_description']); ?></td>
                                    <td><?php echo htmlspecialchars($row['query_date']); ?></td>
                                    <td><?php echo $row['status'] == 1 ? 'Answered' : 'Pending'; ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="5">No queries found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Submit New Query Form -->
        <div class="row mt-5">
            <div class="col-md-8 offset-md-2">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <h4>Submit a New Query</h4>
                        <form action="queries.php" method="POST">
                            <div class="mb-3">
                                <label for="query_subject" class="form-label">Query Subject</label>
                                <input type="text" class="form-control" id="query_subject" name="query_subject" required>
                            </div>
                            <div class="mb-3">
                                <label for="query_description" class="form-label">Query Description</label>
                                <textarea class="form-control" id="query_description" name="query_description" rows="4" required></textarea>
                            </div>
                            <button type="submit" class="btn btn-primary" name="submit_query">Submit Query</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="bg-dark text-white text-center py-3 mt-5">
        <p>&copy; 2025 Care Compass Hospitals | All Rights Reserved</p>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>
