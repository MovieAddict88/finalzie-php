<?php
require_once 'partials/header.php';
require_once '../includes/config.php';

$feedback = '';
$feedback_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn = db_connect();

    $content_type = $_POST['content_type'];
    $title = $_POST['title'];
    $description = $_POST['description'];
    $poster_path = $_POST['poster_path'];
    $rating = (float)$_POST['rating'];
    $parental_rating = $_POST['parental_rating'];

    try {
        if ($content_type === 'movie') {
            $release_date = $_POST['release_date'];
            $runtime = (int)$_POST['runtime'];
            $stmt = $conn->prepare("INSERT INTO movies (title, description, poster_path, release_date, rating, parental_rating, runtime) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssdsi", $title, $description, $poster_path, $release_date, $rating, $parental_rating, $runtime);
        } elseif ($content_type === 'series') {
            $release_date = $_POST['release_date'];
            $stmt = $conn->prepare("INSERT INTO tv_series (title, description, poster_path, first_air_date, rating, parental_rating) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssds", $title, $description, $poster_path, $release_date, $rating, $parental_rating);
        } elseif ($content_type === 'live') {
            // Live TV doesn't have release date or runtime from this form
            $stmt = $conn->prepare("INSERT INTO live_tv (title, description, poster_path, rating, parental_rating) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("sssds", $title, $description, $poster_path, $rating, $parental_rating);
        } else {
            throw new Exception("Invalid content type.");
        }

        if ($stmt->execute()) {
            $feedback = ucfirst($content_type) . " added successfully!";
            $feedback_type = 'success';
        } else {
            throw new Exception($stmt->error);
        }
        $stmt->close();
    } catch (Exception $e) {
        $feedback = 'Error adding content: ' . $e->getMessage();
        $feedback_type = 'error';
    }
    $conn->close();
}
?>

<style>
    .feedback { padding: 15px; border-radius: 8px; margin-bottom: 20px; font-weight: 600; }
    .feedback.success { background-color: rgba(70, 211, 105, 0.1); color: #46d369; border: 1px solid #46d369; }
    .feedback.error { background-color: rgba(244, 6, 18, 0.1); color: #f40612; border: 1px solid #f40612; }
</style>

<!-- Manual Input Tab Content -->
<div id="manual-input">

    <?php if ($feedback): ?>
        <div class="feedback <?php echo $feedback_type; ?>">
            <?php echo htmlspecialchars($feedback); ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <h2>✏️ Manual Content Input</h2>
        <p>Use this form to add a new Movie or TV Series to the database.</p>

        <form action="manual.php" method="POST" id="manual-add-form">
            <div class="form-group">
                <label for="content_type">Content Type</label>
                <select class="form-control" id="content_type" name="content_type" required>
                    <option value="movie">Movie</option>
                    <option value="series">TV Series</option>
                    <option value="live">Live TV</option>
                </select>
            </div>

            <div class="form-group">
                <label for="title">Title</label>
                <input type="text" class="form-control" id="title" name="title" required>
            </div>

            <div class="form-group">
                <label for="description">Description</label>
                <textarea class="form-control" id="description" name="description" rows="5" required></textarea>
            </div>

            <div class="form-group">
                <label for="poster_path">Poster Path (URL)</label>
                <input type="url" class="form-control" id="poster_path" name="poster_path" required>
            </div>

            <div class="form-row">
                <div class="form-group col-md-6">
                    <label for="release_date">Release / First Air Date</label>
                    <input type="date" class="form-control" id="release_date" name="release_date" required>
                </div>
                <div class="form-group col-md-6">
                    <label for="rating">Rating (0.0 - 10.0)</label>
                    <input type="number" step="0.1" min="0" max="10" class="form-control" id="rating" name="rating" required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group col-md-6">
                    <label for="parental_rating">Parental Rating</label>
                    <input type="text" class="form-control" id="parental_rating" name="parental_rating" placeholder="e.g., PG-13">
                </div>
                <div class="form-group col-md-6" id="runtime-group">
                    <label for="runtime">Runtime (minutes)</label>
                    <input type="number" class="form-control" id="runtime" name="runtime" placeholder="e.g., 120">
                </div>
            </div>

            <button type="submit" class="btn btn-primary">Add Content</button>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const contentTypeSelect = document.getElementById('content_type');
    const runtimeGroup = document.getElementById('runtime-group');
    const releaseDateGroup = document.querySelector('label[for="release_date"]').parentElement;

    function toggleFields() {
        const releaseDateInput = document.getElementById('release_date');
        const runtimeInput = document.getElementById('runtime');

        if (contentTypeSelect.value === 'live') {
            releaseDateGroup.style.display = 'none';
            releaseDateInput.required = false;
            runtimeGroup.style.display = 'none';
            runtimeInput.required = false;
        } else if (contentTypeSelect.value === 'series') {
            releaseDateGroup.style.display = 'block';
            releaseDateInput.required = true;
            runtimeGroup.style.display = 'none';
            runtimeInput.required = false;
        } else { // movie
            releaseDateGroup.style.display = 'block';
            releaseDateInput.required = true;
            runtimeGroup.style.display = 'block';
            runtimeInput.required = true;
        }
    }

    // Initial check
    toggleFields();

    // Listen for changes
    contentTypeSelect.addEventListener('change', toggleFields);
});
</script>

<?php
require_once 'partials/footer.php';
?>
