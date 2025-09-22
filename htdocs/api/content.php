<?php
header('Content-Type: application/json');
require_once '../includes/config.php';

$conn = db_connect();
if (!$conn) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

// --- Parameters ---
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
$offset = ($page - 1) * $limit;

$sort = isset($_GET['sort']) ? $_GET['sort'] : 'newest';
$type_filter = isset($_GET['type']) ? $_GET['type'] : 'all';
// Genre/Country/Year filters can be added here later

// --- Build WHERE clause for filtering ---
$where_conditions = [];
$params = [];
$param_types = '';

if ($type_filter !== 'all') {
    $where_conditions[] = "content_type = ?";
    $params[] = $type_filter;
    $param_types .= 's';
}

$where_sql = '';
if (!empty($where_conditions)) {
    $where_sql = 'WHERE ' . implode(' AND ', $where_conditions);
}

// --- Build ORDER BY clause for sorting ---
$order_by_sql = '';
switch ($sort) {
    case 'rating':
        $order_by_sql = 'ORDER BY rating DESC';
        break;
    case 'popular': // Assuming popularity is based on rating for now
        $order_by_sql = 'ORDER BY rating DESC';
        break;
    case 'newest':
    default:
        $order_by_sql = 'ORDER BY release_date DESC';
        break;
}

// --- Build the main query using UNION ALL for performance ---
// This combines movies, series, and live tv into a single result set to be filtered, sorted, and paginated
$base_query = "
    (SELECT
        id,
        'movie' as content_type,
        title,
        description,
        poster_path,
        release_date,
        rating,
        parental_rating,
        runtime
    FROM movies)
    UNION ALL
    (SELECT
        id,
        'series' as content_type,
        title,
        description,
        poster_path,
        first_air_date as release_date,
        rating,
        parental_rating,
        NULL as runtime
    FROM tv_series)
    UNION ALL
    (SELECT
        id,
        'live' as content_type,
        title,
        description,
        poster_path,
        created_at as release_date, /* Use creation date for sorting */
        rating,
        parental_rating,
        NULL as runtime
    FROM live_tv)
";

// --- Get Total Count for Pagination (with filtering) ---
$count_query = "SELECT COUNT(*) as count FROM ({$base_query}) AS combined_content {$where_sql}";
$stmt_count = $conn->prepare($count_query);
if (!empty($params)) {
    $stmt_count->bind_param($param_types, ...$params);
}
$stmt_count->execute();
$total_items = $stmt_count->get_result()->fetch_assoc()['count'];
$stmt_count->close();

// --- Fetch the paginated data ---
$data_query = "SELECT * FROM ({$base_query}) AS combined_content {$where_sql} {$order_by_sql} LIMIT ? OFFSET ?";
$param_types .= 'ii';
$params[] = $limit;
$params[] = $offset;

$stmt_data = $conn->prepare($data_query);
$stmt_data->bind_param($param_types, ...$params);
$stmt_data->execute();
$result_data = $stmt_data->get_result();

// --- Process results ---
$movies_category = ['MainCategory' => 'Movies', 'Entries' => []];
$series_category = ['MainCategory' => 'TV Series', 'Entries' => []];
$live_tv_category = ['MainCategory' => 'Live TV', 'Entries' => []];

while ($item = $result_data->fetch_assoc()) {
    $entry = [
        'id' => $item['id'],
        'type' => $item['content_type'],
        'Title' => $item['title'],
        'Description' => $item['description'],
        'Poster' => $item['poster_path'],
        'Thumbnail' => $item['poster_path'],
        'Rating' => (float)$item['rating'],
        'Year' => $item['release_date'] ? (int)date('Y', strtotime($item['release_date'])) : null,
        'parentalRating' => $item['parental_rating'],
    ];

    if ($item['content_type'] === 'movie' || $item['content_type'] === 'live') {
        $entry['Duration'] = $item['runtime'] > 0 ? gmdate("H:i:s", $item['runtime'] * 60) : 'N/A';
        $entry['Servers'] = []; // Will be fetched later
        if ($item['content_type'] === 'movie') {
            $movies_category['Entries'][] = $entry;
        } else {
            $live_tv_category['Entries'][] = $entry;
        }
    } elseif ($item['content_type'] === 'series') {
        $entry['Seasons'] = []; // Will be fetched later
        $series_category['Entries'][] = $entry;
    }
}
$stmt_data->close();

// --- Batch fetch additional data (servers, seasons, episodes) for the items on the current page ---

// Fetch servers for movies and live tv
$movie_ids = array_map(fn($m) => $m['id'], array_merge($movies_category['Entries'], $live_tv_category['Entries']));
if (!empty($movie_ids)) {
    $ids_str = implode(',', $movie_ids);
    $sql_movie_servers = "SELECT * FROM servers WHERE content_type IN ('movie', 'live') AND content_id IN ({$ids_str})";
    $result_movie_servers = $conn->query($sql_movie_servers);
    $movie_servers = [];
    while($row = $result_movie_servers->fetch_assoc()) {
        $movie_servers[$row['content_id']][] = ['name' => $row['server_name'], 'url' => $row['server_url']];
    }

    // Attach servers to movies
    foreach ($movies_category['Entries'] as &$movie_entry) {
        if (isset($movie_servers[$movie_entry['id']])) {
            $movie_entry['Servers'] = $movie_servers[$movie_entry['id']];
        }
    }

    // Attach servers to live tv entries
    foreach ($live_tv_category['Entries'] as &$live_tv_entry) {
        if (isset($movie_servers[$live_tv_entry['id']])) {
            $live_tv_entry['Servers'] = $movie_servers[$live_tv_entry['id']];
        }
    }
}

// Fetch seasons and episodes for series
$series_ids = array_map(fn($s) => $s['id'], $series_category['Entries']);
if (!empty($series_ids)) {
    // Fetch all seasons for all series on the page in one go
    $ids_str = implode(',', $series_ids);
    $sql_seasons = "SELECT * FROM seasons WHERE series_id IN ({$ids_str}) ORDER BY series_id, season_number ASC";
    $result_seasons = $conn->query($sql_seasons);
    $seasons_by_series = [];
    $all_season_ids = [];
    while($season = $result_seasons->fetch_assoc()) {
        $seasons_by_series[$season['series_id']][] = $season;
        $all_season_ids[] = $season['id'];
    }

    // Fetch all episodes for all seasons on the page in one go
    $episodes_by_season = [];
    if(!empty($all_season_ids)) {
        $season_ids_str = implode(',', $all_season_ids);
        $sql_episodes = "SELECT * FROM episodes WHERE season_id IN ({$season_ids_str}) ORDER BY season_id, episode_number ASC";
        $result_episodes = $conn->query($sql_episodes);
        while($episode = $result_episodes->fetch_assoc()) {
            $episodes_by_season[$episode['season_id']][] = $episode;
        }
    }

    // Structure the data
    foreach ($series_category['Entries'] as &$series_entry) {
        if (isset($seasons_by_series[$series_entry['id']])) {
            foreach($seasons_by_series[$series_entry['id']] as $season_data) {
                $season_entry = [
                    'Season' => (int)$season_data['season_number'],
                    'SeasonPoster' => $season_data['poster_path'] ?: $series_entry['Poster'],
                    'Episodes' => []
                ];
                if(isset($episodes_by_season[$season_data['id']])) {
                    foreach($episodes_by_season[$season_data['id']] as $episode_data) {
                        $season_entry['Episodes'][] = [
                            'id' => $episode_data['id'],
                            'type' => 'episode',
                            'Episode' => (int)$episode_data['episode_number'],
                            'Title' => $episode_data['title'],
                            'Description' => $episode_data['description'],
                            'Thumbnail' => $episode_data['still_path'],
                            'Duration' => $episode_data['runtime'] > 0 ? gmdate("H:i:s", $episode_data['runtime'] * 60) : 'N/A',
                            'Servers' => [] // This could be another batch query if needed
                        ];
                    }
                }
                $series_entry['Seasons'][] = $season_entry;
            }
        }
    }
}


// --- Final JSON Response ---
$cineData = [
    'pagination' => [
        'total_items' => (int)$total_items,
        'page' => $page,
        'limit' => $limit,
        'total_pages' => ceil($total_items / $limit)
    ],
    'categories' => []
];

if (!empty($movies_category['Entries'])) {
    $cineData['categories'][] = $movies_category;
}
if (!empty($series_category['Entries'])) {
    $cineData['categories'][] = $series_category;
}
if (!empty($live_tv_category['Entries'])) {
    $cineData['categories'][] = $live_tv_category;
}

echo json_encode($cineData, JSON_PRETTY_PRINT);
$conn->close();
?>
