<?php
header('Content-Type: application/json');
require_once '../includes/config.php';

$conn = db_connect();

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['action']) || !isset($input['content_id']) || !isset($input['content_type'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid input.']);
    exit;
}

$action = $input['action'];
$content_id = (int)$input['content_id'];
$content_type = $input['content_type'] === 'series' ? 'tv_series' : 'movie'; // Sanitize type

// Ensure the interaction record exists
$conn->query("INSERT INTO interactions (content_id, content_type, views, likes, dislikes) VALUES ({$content_id}, '{$content_type}', 0, 0, 0) ON DUPLICATE KEY UPDATE id=id");

$sql = '';

switch ($action) {
    case 'view':
        $sql = "UPDATE interactions SET views = views + 1 WHERE content_id = ? AND content_type = ?";
        break;
    case 'like':
        // If the user previously disliked it, we remove the dislike and add a like.
        // This logic is simplified on the client side for now.
        $sql = "UPDATE interactions SET likes = likes + 1 WHERE content_id = ? AND content_type = ?";
        break;
    case 'unlike':
        $sql = "UPDATE interactions SET likes = GREATEST(0, likes - 1) WHERE content_id = ? AND content_type = ?";
        break;
    case 'dislike':
        $sql = "UPDATE interactions SET dislikes = dislikes + 1 WHERE content_id = ? AND content_type = ?";
        break;
    case 'undislike':
        $sql = "UPDATE interactions SET dislikes = GREATEST(0, dislikes - 1) WHERE content_id = ? AND content_type = ?";
        break;
    case 'get':
        // Just fetch the counts without updating
        break;
    default:
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid action.']);
        exit;
}

if (!empty($sql)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("is", $content_id, $content_type);
    $stmt->execute();
    $stmt->close();
}

// Always fetch the latest counts and return them
$select_stmt = $conn->prepare("SELECT likes, dislikes, views FROM interactions WHERE content_id = ? AND content_type = ?");
$select_stmt->bind_param("is", $content_id, $content_type);
$select_stmt->execute();
$result = $select_stmt->get_result()->fetch_assoc();
$select_stmt->close();

echo json_encode([
    'status' => 'success',
    'message' => "Action '{$action}' processed.",
    'counts' => [
        'likes' => (int)($result['likes'] ?? 0),
        'dislikes' => (int)($result['dislikes'] ?? 0),
        'views' => (int)($result['views'] ?? 0)
    ]
]);

$conn->close();
?>
