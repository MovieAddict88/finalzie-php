<?php
session_start();
require_once '../includes/config.php';

// Check if user is logged in, if not, redirect to login page
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}

$conn = db_connect();
$error_message = '';
$success_message = '';
$item = null;
$content_type = '';
$content_id = 0;

// --- Handle Form Submission (UPDATE) ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $content_id = $_POST['content_id'];
    $content_type = $_POST['content_type'];
    $title = $_POST['title'];
    $description = $_POST['description'];
    $poster_path = $_POST['poster_path'];
    $rating = $_POST['rating'];
    $parental_rating = $_POST['parental_rating'];

    if ($content_type === 'movie') {
        $release_date = $_POST['release_date'];
        $runtime = $_POST['runtime'];
        $sql = "UPDATE movies SET title=?, description=?, poster_path=?, release_date=?, rating=?, parental_rating=?, runtime=? WHERE id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssssdsii", $title, $description, $poster_path, $release_date, $rating, $parental_rating, $runtime, $content_id);
    } elseif ($content_type === 'series') {
        $first_air_date = $_POST['release_date']; // Use the same field for simplicity
        $sql = "UPDATE tv_series SET title=?, description=?, poster_path=?, first_air_date=?, rating=?, parental_rating=? WHERE id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssssdsi", $title, $description, $poster_path, $first_air_date, $rating, $parental_rating, $content_id);
    } elseif ($content_type === 'live') {
        $sql = "UPDATE live_tv SET title=?, description=?, poster_path=?, rating=?, parental_rating=? WHERE id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssdsi", $title, $description, $poster_path, $rating, $parental_rating, $content_id);
    }

    if ($stmt->execute()) {
        $success_message = "Content updated successfully!";
        // Redirect back to data page after a short delay
        header("refresh:2;url=data.php");
    } else {
        $error_message = "Error updating record: " . $conn->error;
    }
    $stmt->close();
}


// --- Fetch Existing Data for Form (GET) ---
if (isset($_GET['type']) && isset($_GET['id'])) {
    $content_type = $_GET['type'];
    $content_id = (int)$_GET['id'];

    if ($content_type === 'movie') {
        $stmt = $conn->prepare("SELECT * FROM movies WHERE id = ?");
        $stmt->bind_param("i", $content_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $item = $result->fetch_assoc();
        $stmt->close();
    } elseif ($content_type === 'series') {
        // Alias first_air_date to release_date for form consistency
        $stmt = $conn->prepare("SELECT *, first_air_date as release_date FROM tv_series WHERE id = ?");
        $stmt->bind_param("i", $content_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $item = $result->fetch_assoc();
        $stmt->close();
    } elseif ($content_type === 'live') {
        // Add NULL placeholders for fields that don't exist on live_tv table
        $stmt = $conn->prepare("SELECT *, NULL as release_date, NULL as runtime FROM live_tv WHERE id = ?");
        $stmt->bind_param("i", $content_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $item = $result->fetch_assoc();
        $stmt->close();
    } else {
        $error_message = "Invalid content type specified.";
    }

    if (!$item) {
        $error_message = "Content not found.";
    }
} else {
    $error_message = "No content specified to edit.";
}

$conn->close();

// Include header
include 'partials/header.php';
?>

<main class="main-content">
    <div class="container">
        <h1>Edit Content</h1>
        <hr>

        <?php if ($error_message): ?>
            <div class="alert alert-danger"><?php echo $error_message; ?></div>
        <?php endif; ?>

        <?php if ($success_message): ?>
            <div class="alert alert-success"><?php echo $success_message; ?></div>
            <p>You will be redirected back to the data management page shortly.</p>
        <?php endif; ?>

        <?php if ($item): ?>
            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" class="needs-validation" novalidate>
                <input type="hidden" name="content_id" value="<?php echo $content_id; ?>">
                <input type="hidden" name="content_type" value="<?php echo $content_type; ?>">

                <div class="form-group">
                    <label for="title">Title</label>
                    <input type="text" class="form-control" id="title" name="title" value="<?php echo htmlspecialchars($item['title']); ?>" required>
                </div>

                <div class="form-group">
                    <label for="description">Description</label>
                    <textarea class="form-control" id="description" name="description" rows="5" required><?php echo htmlspecialchars($item['description']); ?></textarea>
                </div>

                <div class="form-group">
                    <label for="poster_path">Poster Path (URL)</label>
                    <input type="url" class="form-control" id="poster_path" name="poster_path" value="<?php echo htmlspecialchars($item['poster_path']); ?>" required>
                </div>

                <div class="form-row">
                    <?php if ($content_type === 'movie' || $content_type === 'series'): ?>
                        <div class="form-group col-md-6">
                            <label for="release_date"><?php echo $content_type === 'movie' ? 'Release Date' : 'First Air Date'; ?></label>
                            <input type="date" class="form-control" id="release_date" name="release_date" value="<?php echo htmlspecialchars($item['release_date']); ?>" required>
                        </div>
                    <?php endif; ?>
                    <div class="form-group col-md-6">
                        <label for="rating">Rating (0.0 - 10.0)</label>
                        <input type="number" step="0.1" min="0" max="10" class="form-control" id="rating" name="rating" value="<?php echo (float)$item['rating']; ?>" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label for="parental_rating">Parental Rating</label>
                        <input type="text" class="form-control" id="parental_rating" name="parental_rating" value="<?php echo htmlspecialchars($item['parental_rating']); ?>">
                    </div>
                    <?php if ($content_type === 'movie'): ?>
                        <div class="form-group col-md-6">
                            <label for="runtime">Runtime (minutes)</label>
                            <input type="number" class="form-control" id="runtime" name="runtime" value="<?php echo (int)$item['runtime']; ?>">
                        </div>
                    <?php endif; ?>
                </div>

                <button type="submit" class="btn btn-primary">Save Changes</button>
                <a href="data.php" class="btn btn-secondary">Cancel</a>
            </form>
        <?php endif; ?>
    </div>
</main>

<?php
// Include footer
include 'partials/footer.php';
?>
