<?php
session_start();
require_once '../includes/config.php';

// Check if user is logged in
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}

$conn = db_connect();
$feedback = '';
$feedback_type = '';
$content_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$content_type = isset($_GET['type']) ? $_GET['type'] : '';
$content_title = '';

// --- Handle Server Deletion ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_server_id'])) {
    $server_id_to_delete = (int)$_POST['delete_server_id'];
    $stmt = $conn->prepare("DELETE FROM servers WHERE id = ?");
    $stmt->bind_param("i", $server_id_to_delete);
    if ($stmt->execute()) {
        $feedback = 'Server deleted successfully.';
        $feedback_type = 'success';
    } else {
        $feedback = 'Error deleting server.';
        $feedback_type = 'error';
    }
    $stmt->close();
}

// --- Handle New Server Addition ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_server'])) {
    $server_name = $_POST['server_name'];
    $server_url = $_POST['server_url'];

    if (!empty($server_name) && !empty($server_url)) {
        $stmt = $conn->prepare("INSERT INTO servers (content_id, content_type, server_name, server_url) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("isss", $content_id, $content_type, $server_name, $server_url);
        if ($stmt->execute()) {
            $feedback = 'Server added successfully.';
            $feedback_type = 'success';
        } else {
            $feedback = 'Error adding server.';
            $feedback_type = 'error';
        }
        $stmt->close();
    } else {
        $feedback = 'Server Name and URL are required.';
        $feedback_type = 'error';
    }
}

// --- Fetch content title and existing servers ---
if ($content_id > 0 && !empty($content_type)) {
    if ($content_type === 'movie') {
        $stmt = $conn->prepare("SELECT title FROM movies WHERE id = ?");
    } else { // Assumes 'episode' or other types might be added
        $stmt = $conn->prepare("SELECT title FROM episodes WHERE id = ?");
    }
    $stmt->bind_param("i", $content_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $content_title = $result->fetch_assoc()['title'];
    }
    $stmt->close();

    $stmt_servers = $conn->prepare("SELECT * FROM servers WHERE content_id = ? AND content_type = ? ORDER BY id");
    $stmt_servers->bind_param("is", $content_id, $content_type);
    $stmt_servers->execute();
    $servers_result = $stmt_servers->get_result();
} else {
    die("Invalid content specified.");
}


include 'partials/header.php';
?>

<style>
    .feedback { padding: 15px; border-radius: 8px; margin-bottom: 20px; font-weight: 600; }
    .feedback.success { background-color: rgba(70, 211, 105, 0.1); color: #46d369; border: 1px solid #46d369; }
    .feedback.error { background-color: rgba(244, 6, 18, 0.1); color: #f40612; border: 1px solid #f40612; }
    .server-list li { display: flex; justify-content: space-between; align-items: center; padding: 10px; border-bottom: 1px solid var(--surface-light); }
    .server-list li:last-child { border-bottom: none; }
    .server-list .server-info { flex-grow: 1; }
</style>

<main class="main-content">
    <div class="container">
        <a href="data.php" class="btn btn-secondary mb-3"><i class="fas fa-arrow-left"></i> Back to Content List</a>
        <h1>Manage Servers</h1>
        <p><strong>Content:</strong> <?php echo htmlspecialchars($content_title ?: 'N/A'); ?> (Type: <?php echo htmlspecialchars($content_type); ?>, ID: <?php echo $content_id; ?>)</p>
        <hr>

        <?php if ($feedback): ?>
            <div class="feedback <?php echo $feedback_type; ?>">
                <?php echo htmlspecialchars($feedback); ?>
            </div>
        <?php endif; ?>

        <!-- Add Server Form -->
        <div class="card">
            <h2>Add New Server</h2>
            <form action="" method="POST">
                <input type="hidden" name="add_server" value="1">
                <div class="form-row">
                    <div class="form-group col-md-5">
                        <label for="server_name">Server Name</label>
                        <input type="text" class="form-control" name="server_name" id="server_name" placeholder="e.g., Server F4" required>
                    </div>
                    <div class="form-group col-md-5">
                        <label for="server_url">Server URL</label>
                        <input type="url" class="form-control" name="server_url" id="server_url" placeholder="https://..." required>
                    </div>
                    <div class="form-group col-md-2" style="margin-top: 32px;">
                        <button type="submit" class="btn btn-primary">Add Server</button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Existing Servers List -->
        <div class="card mt-4">
            <h2>Existing Servers</h2>
            <ul class="server-list">
                <?php if ($servers_result->num_rows > 0): ?>
                    <?php while($server = $servers_result->fetch_assoc()): ?>
                        <li>
                            <div class="server-info">
                                <strong><?php echo htmlspecialchars($server['server_name']); ?></strong>
                                <br>
                                <small><?php echo htmlspecialchars($server['server_url']); ?></small>
                            </div>
                            <form action="" method="POST" onsubmit="return confirm('Are you sure you want to delete this server?');">
                                <input type="hidden" name="delete_server_id" value="<?php echo $server['id']; ?>">
                                <button type="submit" class="btn btn-danger btn-small">Delete</button>
                            </form>
                        </li>
                    <?php endwhile; ?>
                <?php else: ?>
                    <li>No servers found for this content.</li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</main>

<?php
$stmt_servers->close();
$conn->close();
include 'partials/footer.php';
?>
