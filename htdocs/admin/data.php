<?php
require_once 'partials/header.php';
require_once '../includes/config.php';
require_once '../includes/lib/json-machine/autoloader_custom.php';
require_once '../includes/import_logic.php';
require_once '../includes/content_manager.php'; // Include the content manager

$conn = db_connect();
$feedback = '';
$feedback_type = '';

// --- Handle Delete Request ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $delete_id = (int)$_POST['delete_id'];
    $delete_type = $_POST['delete_type'];

    if (deleteContent($conn, $delete_id, $delete_type)) {
        $feedback = 'Content deleted successfully.';
        $feedback_type = 'success';
    } else {
        $feedback = 'Error deleting content.';
        $feedback_type = 'error';
    }
}


// --- Handle JSON Import Request ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['json_file'])) {
    $file = $_FILES['json_file'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $feedback = 'Error uploading file. Code: ' . $file['error'];
        $feedback_type = 'error';
    } else {
        // Check file size to determine which import method to use
        $file_size_mb = $file['size'] / 1024 / 1024;

        if ($file_size_mb > 10) {
            // Use streaming for large files
            $import_result = importJsonDataStream($file['tmp_name'], $conn);
            $feedback = $import_result['message'];
            $feedback_type = $import_result['status'];
        } else {
            // Use traditional method for smaller files
            $json_content = file_get_contents($file['tmp_name']);
            $data = json_decode($json_content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $feedback = 'Error parsing JSON: ' . json_last_error_msg();
                $feedback_type = 'error';
            } else {
                $import_result = importJsonData($data, $conn);
                $feedback = $import_result['message'];
                $feedback_type = $import_result['status'];
            }
        }
    }
}

// --- Fetch Content for Display ---
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$type_filter = isset($_GET['type_filter']) ? $_GET['type_filter'] : 'all';
$search_query = isset($_GET['search']) ? $_GET['search'] : '';

$content_data = getContent($conn, [
    'page' => $page,
    'type' => $type_filter,
    'search' => $search_query
]);

$items = $content_data['items'];
$total_pages = $content_data['total_pages'];

$conn->close();
?>

<style>
    .feedback { padding: 15px; border-radius: 8px; margin-bottom: 20px; font-weight: 600; }
    .feedback.success { background-color: rgba(70, 211, 105, 0.1); color: #46d369; border: 1px solid #46d369; }
    .feedback.error { background-color: rgba(244, 6, 18, 0.1); color: #f40612; border: 1px solid #f40612; }
    .preview-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 20px; margin-top: 20px; }
    .preview-item { background: var(--surface-light); border-radius: 10px; overflow: hidden; position: relative; }
    .preview-item img { width: 100%; height: 270px; object-fit: cover; display: block; }
    .preview-item .info { padding: 15px; }
    .preview-item .title { font-weight: 600; margin-bottom: 5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .preview-item .meta { font-size: 0.8rem; color: var(--text-secondary); }
    .preview-item .actions { margin-top: 10px; display: flex; gap: 5px; flex-wrap: wrap; }
    .btn-small { padding: 5px 10px; font-size: 0.8rem; }
    .pagination { margin-top: 20px; text-align: center; }
    .pagination a, .pagination span { margin: 0 5px; color: var(--primary); text-decoration: none; }
    .pagination span { color: var(--text-secondary); }
    .filter-form { display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap; }
</style>

<!-- Data Management Tab Content -->
<div id="data-management">

    <?php if ($feedback): ?>
        <div class="feedback <?php echo $feedback_type; ?>">
            <?php echo htmlspecialchars($feedback); ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <h2>👁️ Content Preview & Management</h2>

        <form class="filter-form" action="data.php" method="GET">
            <input type="text" name="search" placeholder="Search by title..." value="<?php echo htmlspecialchars($search_query); ?>">
            <select name="type_filter" onchange="this.form.submit()">
                <option value="all" <?php if ($type_filter === 'all') echo 'selected'; ?>>All Types</option>
                <option value="movie" <?php if ($type_filter === 'movie') echo 'selected'; ?>>Movies</option>
                <option value="series" <?php if ($type_filter === 'series') echo 'selected'; ?>>TV Series</option>
                <option value="live" <?php if ($type_filter === 'live') echo 'selected'; ?>>Live TV</option>
            </select>
            <button type="submit" class="btn">Filter</button>
        </form>

        <div class="preview-grid">
            <?php foreach ($items as $item): ?>
                <div class="preview-item">
                    <img src="<?php echo htmlspecialchars($item['poster_path'] ?: 'https://via.placeholder.com/180x270?text=No+Image'); ?>" alt="<?php echo htmlspecialchars($item['title']); ?>">
                    <div class="info">
                        <div class="title" title="<?php echo htmlspecialchars($item['title']); ?>"><?php echo htmlspecialchars($item['title']); ?></div>
                        <div class="meta"><?php echo htmlspecialchars(ucfirst($item['type'])); ?> - <?php echo date('Y', strtotime($item['date'])); ?></div>
                        <div class="actions">
                            <form action="data.php" method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this item?');">
                                <input type="hidden" name="delete_id" value="<?php echo $item['id']; ?>">
                                <input type="hidden" name="delete_type" value="<?php echo $item['type']; ?>">
                                <button type="submit" class="btn btn-danger btn-small">Delete</button>
                            </form>
                            <a href="edit.php?type=<?php echo $item['type']; ?>&id=<?php echo $item['id']; ?>" class="btn btn-secondary btn-small">Edit</a>
                            <?php if ($item['type'] === 'movie' || $item['type'] === 'live'): ?>
                                <a href="manage_servers.php?type=<?php echo $item['type']; ?>&id=<?php echo $item['id']; ?>" class="btn btn-warning btn-small">Servers</a>
                            <?php else: ?>
                                <a href="#" class="btn btn-warning btn-small" disabled title="Server management for series is coming soon.">Servers</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (empty($items)): ?>
                <p>No content found.</p>
            <?php endif; ?>
        </div>

        <div class="pagination">
            <?php if ($page > 1): ?>
                <a href="?page=<?php echo $page - 1; ?>&type_filter=<?php echo $type_filter; ?>&search=<?php echo $search_query; ?>">Previous</a>
            <?php endif; ?>

            <span>Page <?php echo $page; ?> of <?php echo $total_pages; ?></span>

            <?php if ($page < $total_pages): ?>
                <a href="?page=<?php echo $page + 1; ?>&type_filter=<?php echo $type_filter; ?>&search=<?php echo $search_query; ?>">Next</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <h2>📂 Import JSON to Database</h2>
        <p>Upload an existing <code>playlist.json</code> file to populate the database.</p>
        <form action="data.php" method="post" enctype="multipart/form-data">
            <div class="form-group">
                <label for="json_file">Select JSON File</label>
                <input type="file" name="json_file" id="json_file" accept=".json" required>
            </div>
            <button type="submit" class="btn btn-primary">Import Data</button>
        </form>
    </div>
</div>

<?php
require_once 'partials/footer.php';
?>
