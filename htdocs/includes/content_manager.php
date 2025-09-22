<?php

/**
 * Fetches content from the database with pagination and filtering.
 *
 * @param mysqli $conn The database connection.
 * @param array $options An array of options including 'page', 'type', and 'search'.
 * @return array An array containing the content items and total pages.
 */
function getContent($conn, $options = []) {
    $limit = 50; // Items per page
    $page = isset($options['page']) ? (int)$options['page'] : 1;
    $offset = ($page - 1) * $limit;
    $type = isset($options['type']) ? $options['type'] : 'all';
    $search = isset($options['search']) ? $options['search'] : '';

    // A single, unified query for all content types
    $base_query = "
        (SELECT id, 'movie' as type, title, poster_path, release_date as date FROM movies)
        UNION ALL
        (SELECT id, 'series' as type, title, poster_path, first_air_date as date FROM tv_series)
        UNION ALL
        (SELECT id, 'live' as type, title, poster_path, created_at as date FROM live_tv)
    ";

    $where_clauses = [];
    $params = [];
    $param_types = '';

    // Apply type filter if not 'all'
    if ($type !== 'all') {
        $where_clauses[] = "type = ?";
        $params[] = $type;
        $param_types .= 's';
    }

    // Apply search filter
    if (!empty($search)) {
        $where_clauses[] = "title LIKE ?";
        $params[] = "%" . $search . "%";
        $param_types .= 's';
    }

    $where_sql = '';
    if (!empty($where_clauses)) {
        $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
    }

    // Get total count with filters applied
    $count_query = "SELECT COUNT(*) as total FROM ({$base_query}) AS combined_content {$where_sql}";
    $stmt_count = $conn->prepare($count_query);
    if (!empty($params)) {
        $stmt_count->bind_param($param_types, ...$params);
    }
    $stmt_count->execute();
    $total_items = $stmt_count->get_result()->fetch_assoc()['total'];
    $stmt_count->close();

    // Get paginated results with filters and sorting
    $data_query = "SELECT * FROM ({$base_query}) AS combined_content {$where_sql} ORDER BY date DESC LIMIT ? OFFSET ?";
    $data_params = $params;
    $data_params[] = $limit;
    $data_params[] = $offset;
    $data_param_types = $param_types . 'ii';

    $stmt_data = $conn->prepare($data_query);
    $stmt_data->bind_param($data_param_types, ...$data_params);
    $stmt_data->execute();
    $result = $stmt_data->get_result();
    $items = [];
    while ($row = $result->fetch_assoc()) {
        $items[] = $row;
    }
    $stmt_data->close();

    return [
        'items' => $items,
        'total_pages' => ceil($total_items / $limit)
    ];
}

/**
 * Deletes a content item from the database.
 *
 * @param mysqli $conn The database connection.
 * @param int $id The ID of the content to delete.
 * @param string $type The type of content ('movie', 'series', or 'live').
 * @return bool True on success, false on failure.
 */
function deleteContent($conn, $id, $type) {
    $stmt = null;
    if ($type === 'movie') {
        $stmt = $conn->prepare("DELETE FROM movies WHERE id = ?");
    } elseif ($type === 'series') {
        $stmt = $conn->prepare("DELETE FROM tv_series WHERE id = ?");
    } elseif ($type === 'live') {
        $stmt = $conn->prepare("DELETE FROM live_tv WHERE id = ?");
    } else {
        return false;
    }

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param("i", $id);
    $success = $stmt->execute();
    $stmt->close();

    return $success;
}

?>
