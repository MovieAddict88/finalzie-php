<?php
require_once 'includes/config.php';

$conn = db_connect();
$all_content = null;

if ($conn) {
    // This is a simplified version of the logic from api/content.php
    // In a real application, you would abstract this into a shared function.
    $cineData = ['Categories' => []];
    $movies_category = ['MainCategory' => 'Movies', 'SubCategories' => [], 'Entries' => []];
    $livetv_category = ['MainCategory' => 'Live TV', 'SubCategories' => [], 'Entries' => []];
    $series_category = ['MainCategory' => 'TV Series', 'SubCategories' => [], 'Entries' => []];

    // Fetch all servers first and map them by content ID for efficiency
    $all_servers = [];
    $sql_servers = "SELECT * FROM servers";
    $result_servers = $conn->query($sql_servers);
    while($row = $result_servers->fetch_assoc()) {
        $key = $row['content_type'] . '_' . $row['content_id'];
        if (!isset($all_servers[$key])) {
            $all_servers[$key] = [];
        }
        $all_servers[$key][] = ['name' => $row['server_name'], 'url' => $row['server_url']];
    }

    // Fetch Movies
    $sql_movies = "SELECT * FROM movies ORDER BY release_date DESC";
    $result_movies = $conn->query($sql_movies);
    while ($movie = $result_movies->fetch_assoc()) {
        $servers_key = 'movie_' . $movie['id'];
        $entry = [
            'id' => $movie['id'],
            'type' => 'movie',
            'Title' => $movie['title'],
            'Description' => $movie['description'],
            'Poster' => $movie['poster_path'],
            'Thumbnail' => $movie['poster_path'],
            'Rating' => (float)$movie['rating'],
            'Duration' => $movie['runtime'] > 0 ? gmdate("H:i:s", $movie['runtime'] * 60) : 'N/A',
            'Year' => !empty($movie['release_date']) ? (int)date('Y', strtotime($movie['release_date'])) : null,
            'parentalRating' => $movie['parental_rating'],
            'Servers' => isset($all_servers[$servers_key]) ? $all_servers[$servers_key] : []
        ];
        $movies_category['Entries'][] = $entry;
    }

    // Fetch Live TV
    $sql_livetv = "SELECT * FROM live_tv ORDER BY created_at DESC";
    $result_livetv = $conn->query($sql_livetv);
    while ($livetv = $result_livetv->fetch_assoc()) {
        $servers_key = 'live_' . $livetv['id'];
        $entry = [
            'id' => $livetv['id'],
            'type' => 'live',
            'Title' => $livetv['title'],
            'Description' => $livetv['description'],
            'Poster' => $livetv['poster_path'],
            'Thumbnail' => $livetv['poster_path'],
            'Rating' => 0, // Live TV might not have ratings
            'Duration' => 'N/A',
            'Year' => (int)date('Y', strtotime($livetv['created_at'])),
            'parentalRating' => null, // Or fetch if available
            'Servers' => isset($all_servers[$servers_key]) ? $all_servers[$servers_key] : []
        ];
        $livetv_category['Entries'][] = $entry;
    }

    // Fetch TV Series
    $sql_series = "SELECT * FROM tv_series ORDER BY first_air_date DESC";
    $result_series = $conn->query($sql_series);
    while ($series = $result_series->fetch_assoc()) {
        $series_entry = [
            'id' => $series['id'],
            'type' => 'series',
            'Title' => $series['title'],
            'Description' => $series['description'],
            'Poster' => $series['poster_path'],
            'Thumbnail' => $series['poster_path'],
            'Rating' => (float)$series['rating'],
            'Year' => (int)date('Y', strtotime($series['first_air_date'])),
            'parentalRating' => $series['parental_rating'],
            'Seasons' => []
        ];

        $sql_seasons = "SELECT * FROM seasons WHERE series_id = {$series['id']} ORDER BY season_number ASC";
        $result_seasons = $conn->query($sql_seasons);
        while($season = $result_seasons->fetch_assoc()) {
            $season_entry = [
                'Season' => (int)$season['season_number'],
                'SeasonPoster' => $season['poster_path'] ?: $series['poster_path'],
                'Episodes' => []
            ];

            $sql_episodes = "SELECT * FROM episodes WHERE season_id = {$season['id']} ORDER BY episode_number ASC";
            $result_episodes = $conn->query($sql_episodes);
            while($episode = $result_episodes->fetch_assoc()) {
                $servers_key = 'episode_' . $episode['id'];
                $episode_entry = [
                    'id' => $episode['id'],
                    'type' => 'episode',
                    'Episode' => (int)$episode['episode_number'],
                    'Title' => $episode['title'],
                    'Duration' => $episode['runtime'] > 0 ? gmdate("H:i:s", $episode['runtime'] * 60) : 'N/A',
                    'Description' => $episode['description'],
                    'Thumbnail' => $episode['still_path'],
                    'Servers' => isset($all_servers[$servers_key]) ? $all_servers[$servers_key] : []
                ];
                $season_entry['Episodes'][] = $episode_entry;
            }
            $series_entry['Seasons'][] = $season_entry;
        }
        $series_category['Entries'][] = $series_entry;
    }

    if (!empty($livetv_category['Entries'])) $cineData['Categories'][] = $livetv_category;
    if (!empty($movies_category['Entries'])) $cineData['Categories'][] = $movies_category;
    if (!empty($series_category['Entries'])) $cineData['Categories'][] = $series_category;

    $all_content = $cineData;
    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CineCraze</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flag-icon-css@4.1.7/css/flag-icons.min.css">
    <link rel="stylesheet" href="https://cdn.plyr.io/3.7.8/plyr.css">
    <link rel="stylesheet" href="animations.css">
    <style>
        /* Notification Bar Styles */
        .notification-bar {
            background-color: var(--primary);
            color: white;
            padding: 15px 20px;
            text-align: center;
            position: fixed;
            top: 0;
            width: 100%;
            z-index: 1001;
            display: none; /* Initially hidden */
            align-items: center;
            justify-content: center;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }

        .notification-bar p {
            margin: 0;
            font-size: 16px;
        }

        .notification-bar a {
            color: white;
            text-decoration: underline;
            font-weight: bold;
        }

        .notification-bar .close-btn {
            background: none;
            border: none;
            color: white;
            font-size: 20px;
            cursor: pointer;
            margin-left: 20px;
        }
        :root {
            --primary: #e50914;
            --primary-dark: #b20710;
            --movie-badge: #007bff;
            --series-badge: #28a745;
            --live-badge: #e50914;
            --dark: #141414;
            --dark-2: #1a1a1a;
            --dark-3: #222222;
            --light: #f5f5f5;
            --light-2: #e6e6e6;
            --gray: #8c8c8c;
            --transition: all 0.3s ease;
            --shadow: 0 5px 15px rgba(0, 0, 0, 0.5);
            --radius: 12px;
            --header-height: 70px;
            --footer-height: 120px;
            --youtube-red: #ff0000;
            --youtube-dark: #0f0f0f;
            --youtube-gray: #272727;
            --youtube-light-gray: #aaaaaa;
            --youtube-dark-gray: #181818;
            --youtube-light: #f1f1f1;
            --youtube-blue: #3ea6ff;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Roboto', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-color: var(--youtube-dark);
            color: var(--light);
            overflow-x: hidden;
            transition: background-color 0.3s ease;
        }

        /* Header Styles */
        header {
            background: var(--youtube-dark);
            padding: 15px 5%;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            z-index: 1000;
            display: flex;
            align-items: center;
            justify-content: space-between;
            transition: var(--transition);
            border-bottom: 1px solid var(--youtube-gray);
        }

        header.scrolled {
            background-color: var(--youtube-dark);
            box-shadow: var(--shadow);
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 28px;
            font-weight: 700;
            color: var(--youtube-red);
            text-decoration: none;
        }

        /* .logo i { */ /* This rule is no longer needed as the <i> tag was replaced */
            /* color: var(--youtube-red); */
        /* } */

        .logo img {
            height: 30px; 
            width: 30px;  /* Set width equal to height for a circle */
            border-radius: 50%; /* Makes the image circular */
            object-fit: cover; /* Ensures the image covers the area, useful if not square */
            /* No specific color property needed for img, but ensure it's not affected by parent color if it were an SVG with currentColog */
        }

        .search-container {
            position: relative;
            width: 40%; /* Default for desktop */
            transition: width 0.3s ease;
            display: flex; /* Visible by default on desktop */
            align-items: center;
        }

        .search-input-container {
            position: relative;
            width: 100%;
        }

        .search-container input {
            width: 100%;
            padding: 10px 15px;
            padding-right: 40px;
            border-radius: 30px;
            border: none;
            background-color: var(--youtube-gray);
            color: var(--light);
            font-size: 16px;
            transition: var(--transition);
        }

        .search-container input:focus {
            outline: none;
            background-color: var(--dark-3);
            box-shadow: 0 0 0 2px var(--youtube-red);
        }

        .search-results {
            position: absolute;
            top: 100%;
            left: 0;
            width: 100%;
            background-color: var(--dark-3);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            max-height: 300px;
            overflow-y: auto;
            display: none;
            z-index: 1001;
        }

        .search-result-item {
            padding: 12px 15px;
            display: flex;
            align-items: center;
            gap: 15px;
            border-bottom: 1px solid var(--dark-2);
            cursor: pointer;
            transition: var(--transition);
        }

        .search-result-item:hover {
            background-color: var(--dark-2);
        }

        .search-message {
            padding: 12px 15px;
            color: var(--light-2);
            font-size: 14px;
            border-bottom: 1px solid var(--dark-2);
        }

        .search-result-item img {
            width: 50px;
            height: 70px;
            object-fit: cover;
            border-radius: 4px;
        }

        .search-result-info h4 {
            font-size: 16px;
            margin-bottom: 5px;
        }

        .search-result-info p {
            font-size: 14px;
            color: var(--gray);
        }

        .close-search-btn {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--gray);
            cursor: pointer;
            z-index: 3;
        }

        .header-controls {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .theme-toggle {
            background: none;
            border: none;
            color: var(--light);
            font-size: 22px;
            cursor: pointer;
            transition: var(--transition);
        }

        .theme-toggle:hover {
            color: var(--youtube-red);
        }

        .user-profile {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: var(--youtube-red);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            cursor: pointer;
        }

        /* Main Content */
        main {
            padding-top: var(--header-height);
            min-height: calc(100vh - var(--header-height) - var(--footer-height));
        }

        .carousel {
            margin: 0 5% 30px;
            border-radius: var(--radius);
            overflow: hidden;
            position: relative;
            height: 400px;
        }

        .carousel-inner {
            display: flex;
            height: 100%;
            transition: transform 0.5s ease;
        }

        .carousel-item {
            min-width: 100%;
            height: 100%;
            position: relative;
        }

        .carousel-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .carousel-content {
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            padding: 30px;
            background: linear-gradient(0deg, rgba(0,0,0,0.9) 0%, transparent 100%);
        }

        .carousel-content h2 {
            font-size: 2.5rem;
            margin-bottom: 10px;
        }

        .carousel-content p {
            max-width: 600px;
            margin-bottom: 20px;
            color: var(--light-2);
            font-size: 1em; /* Base size for description */
            line-height: 1.5;
        }

        .carousel-meta {
            display: flex;
            align-items: center;
            gap: 15px; /* Space between rating and year */
            margin-bottom: 10px; /* Space below meta and above description */
            color: var(--light-2); /* General color for meta items */
        }

        .carousel-meta .star-rating {
            font-size: 0.9em; /* Slightly smaller stars in carousel */
        }
        .carousel-meta .star-rating .fas.fa-star,
        .carousel-meta .star-rating .fas.fa-star-half-alt {
            color: gold;
        }
        .carousel-meta .star-rating .far.fa-star {
            color: rgba(255, 255, 255, 0.5); /* Lighter empty stars for dark background */
        }

        .carousel-year {
            font-size: 0.9em;
            font-weight: 500;
        }

        .carousel-controls {
            position: absolute;
            top: 50%;
            width: 100%;
            display: flex;
            justify-content: space-between;
            padding: 0 20px;
            transform: translateY(-50%);
        }

        .carousel-btn {
            background: rgba(0,0,0,0.5);
            border: none;
            color: white;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            font-size: 20px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: var(--transition);
        }

        .carousel-btn:hover {
            background: var(--youtube-red);
        }

        .carousel-indicators {
            position: absolute;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            gap: 10px;
        }

        .indicator {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: rgba(255,255,255,0.5);
            cursor: pointer;
            transition: var(--transition);
        }

        .indicator.active {
            background: var(--youtube-red);
            transform: scale(1.2);
        }

        /* Filters Section */
        .filters-section {
            padding: 0 5%;
            margin-bottom: 30px;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .section-title {
            font-size: 24px;
            font-weight: 700;
        }

        .filters-row {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 20px;
        }

        .filter-group {
            flex: 1;
            min-width: 200px;
        }

        .filter-group label {
            display: block;
            margin-bottom: 8px;
            font-size: 14px;
            color: var(--gray);
        }

        .filter-select {
            width: 100%;
            padding: 12px 15px;
            border-radius: var(--radius);
            background-color: var(--youtube-gray);
            border: 1px solid var(--dark-2);
            color: var(--light);
            font-size: 16px;
            cursor: pointer;
        }

        .autocomplete-container {
            position: relative;
        }

        .autocomplete-items {
            position: absolute;
            border: 1px solid var(--dark-2);
            border-top: none;
            z-index: 99;
            top: 100%;
            left: 0;
            right: 0;
            background-color: var(--youtube-gray);
            border-radius: 0 0 var(--radius) var(--radius);
            overflow-y: auto;
            max-height: 200px;
        }

        .autocomplete-items div {
            padding: 10px;
            cursor: pointer;
            background-color: var(--youtube-gray);
            color: var(--light);
        }

        .autocomplete-items div:hover {
            background-color: var(--dark-3);
        }

        .autocomplete-active {
            background-color: var(--youtube-red) !important;
            color: #ffffff;
        }

        .view-toggle {
            display: flex;
            gap: 10px;
        }

        .view-btn {
            background-color: var(--youtube-gray);
            border: none;
            color: var(--light);
            width: 40px;
            height: 40px;
            border-radius: var(--radius);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: var(--transition);
        }

        .view-btn.active, .view-btn:hover {
            background-color: var(--youtube-red);
        }

        /* Content Grid */
        .content-container {
            padding: 0 5% 50px;
        }

        .content-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 25px;
        }

        .content-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
            display: none;
        }

        .content-card {
            border-radius: var(--radius);
            overflow: hidden;
            transition: var(--transition);
            cursor: pointer;
            position: relative;
}

.content-card.grid {
    aspect-ratio: 2 / 3; /* Enforce consistent aspect ratio for desktop grid cards */
        }

        .content-card:hover {
            transform: translateY(-10px);
            box-shadow: var(--shadow);
        }

        @media (max-width: 480px) {
  .content-card.grid {
    aspect-ratio: auto;
    height: auto;
    min-height: 300px;
  }
}


        .content-card.list {
            display: flex;
            height: 150px;
        }

        .content-card.list .card-img {
            width: 100px;
            height: 100%;
        }

        .card-img {
            width: 100%;
            height: 100%;
            position: relative;
        }

        .content-card.grid .card-img {
            height: 85%;
        }

        .card-img img {
    display: block; /* Ensure image behaves as a block-level element */
            width: 100%;
            height: 100%;
            object-fit: cover;
    /* transition: var(--transition); Removed as opacity transition is more specific */
            opacity: 0; /* Start with opacity 0 for lazy loading */
            transition: opacity 0.3s ease;
        }

        .card-img img.loaded {
            opacity: 1;
        }

        .card-badge {
            position: absolute;
            top: 10px;
            left: 10px;
            color: white;
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 700;
            z-index: 2;
        }

        .badge-movie {
            background-color: var(--movie-badge);
        }

        .badge-series {
            background-color: var(--series-badge);
        }

        .badge-live {
            background-color: var(--live-badge);
        }

        .delete-watch-later-btn {
            position: absolute;
            top: 10px;
            right: 10px;
            background: rgba(229, 9, 20, 0.8);
            border: none;
            color: white;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            font-size: 14px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: var(--transition);
            z-index: 3;
        }
        .delete-watch-later-btn:hover {
            background: var(--primary-dark);
            transform: scale(1.1);
        }

        .content-card.grid .card-watch-later-btn {
            position: absolute;
            top: 10px;
            right: 10px;
            background: rgba(0, 0, 0, 0.7);
            border: none;
            color: white;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            font-size: 14px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: var(--transition);
            z-index: 3;
        }
        .content-card.grid .card-watch-later-btn:hover {
            background: var(--primary);
            transform: scale(1.1);
        }

        .content-card.grid .card-watch-later-btn.active {
            background: var(--primary);
        }

        .content-card.grid .card-watch-later-btn.active:hover {
            background: var(--primary-dark);
        }

        .card-info {
            padding: 15px;
        }

        .card-title-container {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 10px;
        }

        .content-card.list .card-title-container {
            align-items: center;
        }

        .content-card.list .card-watch-later-btn {
            background: none;
            border: 1px solid var(--gray);
            color: var(--gray);
            width: 28px;
            height: 28px;
            border-radius: 50%;
            font-size: 12px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: var(--transition);
            flex-shrink: 0;
        }

        .content-card.list .card-watch-later-btn:hover {
            background: var(--primary-dark);
            color: white;
            border-color: var(--primary-dark);
        }

        .content-card.list .card-watch-later-btn.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

.content-card.grid .card-info {
  padding: 8px; /* Reduced padding to save space */
  background-color: var(--youtube-dark-gray);
  display: flex;
  flex-direction: column;
  justify-content: flex-start; /* Align content to the top */
  align-items: center;
  text-align: center;
  flex-grow: 1;
  overflow: hidden; /* Hide overflow from card-info itself */
}

.content-card.grid .card-title {
    height: auto; /* Allow title to define its own height up to max-height / line-clamp */
    margin-bottom: 5px; /* Add some space below the title before meta */
}

.content-card.grid .card-meta {
    /* display: none; */ /* Hide rating and duration in grid view - Commented out to show meta */
    display: flex; /* Ensure it's a flex container */
    flex-direction: column; /* Stack items vertically */
    align-items: center; /* Center items horizontally */
    gap: 4px; /* Reduced gap between meta items slightly */
    margin-top: 0; /* Reset margin-top as title has margin-bottom */
    padding: 0 5px; /* Add some horizontal padding if needed */
    width: 100%; /* Take full width to allow text centering */
}

.content-card.grid .card-meta .star-rating {
    font-size: 12px; /* Slightly smaller star size for grid */
}

.content-card.grid .card-meta span { /* Target direct span children for duration/country */
    font-size: 11px; /* Slightly smaller text size for duration/country */
    text-align: center; /* Ensure text is centered */
    display: inline-flex; /* To allow icon and text on same line if needed */
    align-items: center;
    gap: 3px; /* Space between icon and text if any */
}

.content-card.grid .card-meta .meta-country {
    white-space: nowrap; /* Prevent country name from wrapping */
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 90%; /* Prevent very long country names from breaking layout */
}

.content-card.grid .card-meta .meta-country .fi {
    font-size: 0.8em; /* Slightly smaller flag in grid */
    flex-shrink: 0; /* Prevent flag from shrinking */
}

.content-card.grid .card-meta span .fas.fa-clock {
    color: inherit; /* Make clock icon inherit color from parent span (i.e., neutral) */
}

/* Adjustments to ensure card-info does not exceed its allocated 15% height too much */
.content-card.grid {
    /* aspect-ratio: 2 / 3; already defined */
    /* Ensure the card itself handles overflow if card-info becomes too tall */
    overflow: hidden; 
}

.content-card.grid .card-img {
    height: 70%; /* Adjusted image height after removing description from grid info */
}

/* For very small screens where aspect-ratio is auto, image height is also 70% */
/* No specific override needed here if the main rule is now 70% unless a different value is desired */
/* The @media (max-width: 480px) .content-card.grid .card-img rule can be removed if it's also 70% */
/* For clarity, I will remove the specific override if it's the same as the new base. */

@media (max-width: 480px) {
  /* .content-card.grid .card-img { height: 70%; } */ /* This is now covered by the general rule above */

  .content-card.grid .card-info {
    min-height: 100px;
    justify-content: flex-start; /* Align content to top */
  }

  .content-card.grid .card-meta {
    display: flex; /* Ensure meta is still shown, removing the old display:none rule from this breakpoint */
  }

          /* General card title adjustments for very small screens */
          .card-title { /* Applies to both grid and list unless overridden */
    font-size: 13px;
            -webkit-line-clamp: 2; /* Ensure 2 lines */
            /* height was !important, use max-height if possible for flexibility */
            max-height: calc(13px * 1.4 * 2);
            overflow: hidden !important; /* Retained from previous fix, but check if needed */
          }
          /* Specific override for list view title if needed, or let general rule apply */
          .content-card.list .card-title {
             font-size: 14px; /* Example: slightly larger than grid title on smallest screens */
             max-height: calc(14px * 1.4 * 2);
  }


  .card-meta { /* This rule still applies to .content-card.list .card-meta */
            font-size: 11px; /* Already set */
             margin-top: 3px; /* Adjust margin */
  }
           .content-card.list .card-meta { /* Specific for list view on smallest screens */
               font-size: 11px;
           }


  .content-card.list .card-description {
    font-size: 12px; /* Adjust description font size for small screens */
    line-height: 1.4; /* Ensure good line spacing for readability */
    display: -webkit-box;
    -webkit-box-orient: vertical;
            -webkit-line-clamp: 2; /* Limit to 2 lines */
    overflow: hidden;
    text-overflow: ellipsis;
            margin-top: 3px; /* Adjust margin */
            /* max-height: calc(12px * 1.4 * 2); /* Fallback for non-webkit */
    */
  }
}


        .content-card.list .card-info {
            flex: 1;
            padding: 15px 20px;
        }


.card-title {
  font-weight: 600;
  line-height: 1.4;
  color: var(--light);
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2; /* default 2 lines */
  overflow: hidden;
  text-overflow: ellipsis;
  word-break: break-word;
  font-size: 16px;
  max-height: 3.0em; /* Use max-height instead of height for flexibility */
  width: 100%; /* Ensure title takes full width of its container */
}

.content-card.grid .card-title {
    height: auto; /* Allow title to define its own height up to max-height / line-clamp */
}


.card-meta {
    display: flex;
    gap: 10px;
    font-size: 14px;
    color: var(--youtube-light-gray);
}

.content-card.grid .card-meta {
    /* display: none; */ /* Hide rating and duration in grid view - Commented out to show meta */
    display: flex; /* Ensure it's a flex container */
    flex-direction: column; /* Stack items vertically */
    align-items: center; /* Center items horizontally */
    gap: 5px; /* Add some space between meta items */
    margin-top: 8px; /* Add space above the meta section */
    padding: 0 5px; /* Add some horizontal padding if needed */
}

.content-card.grid .card-meta .star-rating {
    font-size: 13px; /* Adjust star size for grid */
}

.content-card.grid .card-meta span { /* Target direct span children for duration/country */
    font-size: 12px; /* Adjust text size for duration/country */
    text-align: center; /* Ensure text is centered */
}

/* Ensure Netflix-style meta for list view is not affected if it shares general span tags */
.content-card.list .card-meta .meta-netflix-style span {
    font-size: 13px; /* Keep original list view font size or adjust as needed */
    text-align: left; /* Keep original alignment for list view */
}

.content-card.grid .card-meta .meta-country .fi {
    font-size: 0.9em; /* Slightly smaller flag in grid */
}






        .card-meta span {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .card-meta i {
            color: var(--youtube-red);
    font-size: 18px; /* Default for desktop grid and list, and larger mobile list */
        }

        .star-rating {
            display: inline-flex;
            align-items: center;
            gap: 2px; /* Space between stars */
        }

        .star-rating .fas.fa-star,
        .star-rating .fas.fa-star-half-alt {
            color: gold; /* Gold color for full and half stars */
        }
        .star-rating .far.fa-star {
            color: #ccc; /* Color for empty stars */
        }

        .content-card.list .card-meta .meta-netflix-style {
            display: flex;
            flex-wrap: wrap; /* Allow wrapping if space is tight, though unlikely for 3 items */
            align-items: center;
            gap: 0; /* Remove gap from parent .card-meta if any was applied directly here */
            color: var(--youtube-light-gray); /* Standard light gray for meta text */
            font-size: 13px; /* Slightly smaller font */
        }

        .content-card.list .card-meta .meta-netflix-style span:not(:empty):not(:last-child)::after {
            content: "·";
            margin: 0 0.4em; /* Space around the dot */
            color: var(--youtube-light-gray); /* Dot color */
        }
        .content-card.list .card-meta .meta-netflix-style span:empty {
            display: none; /* Hide empty spans (if year/country/duration is missing) */
        }

        .content-card.list .card-meta .meta-netflix-style .meta-country {
            display: inline-flex; /* Align flag and text */
            align-items: center;
            gap: 0.3em; /* Space between flag and country name */
        }

        .content-card.list .card-meta .meta-netflix-style .meta-country .fi {
            /* Adjust size if needed, flag-icon-css default is usually good */
            /* line-height: 1; /* Helps with vertical alignment if text is taller */
        }


        /* Live TV: uniform, non-cropped logos */
        .content-card[data-type="live"] .card-img {
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: var(--youtube-dark-gray);
        }
        .content-card[data-type="live"] .card-img img {
            object-fit: contain;
            width: 100%;
            height: 100%;
            padding: 8%;
        }

        /* Viewer Page - YouTube Style */
        .viewer-page {
            display: none;
            padding: 30px 5% 50px;
            background: var(--youtube-dark);
            position: relative;
        }

        .youtube-viewer-container {
            display: flex;
            flex-direction: column;
            max-width: 1800px;
            margin: 0 auto;
        }

        .player-container {
            width: 100%;
            position: relative;
            padding-top: 56.25%; /* 16:9 Aspect Ratio */
            border-radius: var(--radius);
            overflow: hidden;
            box-shadow: var(--shadow);
            margin-bottom: 20px;
            background: #000;
        }

        .plyr {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            border-radius: var(--radius);
        }

        /* UPDATED: Viewer Content - YouTube Style Grid Layout */
        .viewer-content {
            display: grid;
            grid-template-columns: 1fr; /* Default for mobile */
            gap: 20px; /* Reduced gap */
        }

        @media (min-width: 992px) { /* Apply grid for larger screens */
            .viewer-content {
                grid-template-columns: minmax(0, 2.5fr) minmax(0, 1fr); /* Main content and sidebar */
                gap: 24px;
                align-items: start; /* Align items to the start of the grid cell */
            }
            .related-videos {
                /* Make sidebar scrollable if content exceeds viewport height */
                /* Consider viewport height minus header/player height for max-height */
                max-height: calc(100vh - var(--header-height) - 40px); /* Adjust 40px for padding/margins */
                overflow-y: auto;
                padding-right: 5px; /* Space for scrollbar if it appears */
            }
        }

        .video-details {
            /* flex: 1; No longer needed with grid */
            min-width: 0; /* Allow shrinking in grid */
        }

        .video-info {
            margin-bottom: 20px;
        }

        .video-title {
            font-size: 24px;
            margin-bottom: 12px;
            color: var(--light);
        }

        .video-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--youtube-gray);
            margin-bottom: 20px;
        }

        .video-stats {
            display: flex;
            gap: 20px;
            color: var(--youtube-light-gray);
            font-size: 14px;
        }

        .video-stats span {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .video-actions {
            display: flex;
            gap: 15px;
        }

        .action-btn {
            display: flex;
            align-items: center;
            gap: 8px;
            background: none;
            border: none;
            color: var(--light);
            cursor: pointer;
            font-size: 14px;
            transition: var(--transition);
        }

        .action-btn:hover,
        .action-btn.active { /* Added .active state */
            color: var(--youtube-red);
        }

        .action-btn#stretch-btn.active {
            color: var(--series-badge);
        }

        .action-btn i {
            font-size: 18px;
        }

        .video-description {
            background-color: var(--youtube-gray);
            border-radius: var(--radius);
            padding: 15px;
            margin-bottom: 25px;
            line-height: 1.6;
            font-size: 15px;
            white-space: pre-line;
        }

        .episode-selector-container {
            display: none; /* JS handles visibility */
            margin-bottom: 20px;
            background-color: var(--youtube-gray); /* Keep background for grouping */
            border-radius: var(--radius);
            padding: 15px;
        }
        .episode-selector-container h3 {
            font-size: 16px;
            color: var(--light);
            margin-bottom: 10px;
            font-weight: 600;
        }

        .episode-selector {
            width: 100%;
            padding: 10px 12px; /* Adjusted padding */
            border-radius: 4px; /* Sharper radius */
            background-color: var(--youtube-dark-gray); /* Darker background for select */
            border: 1px solid var(--dark-3); /* Subtle border */
            color: var(--light);
            font-size: 14px; /* Slightly smaller font */
            cursor: pointer;
        }
        .episode-selector:focus {
            outline: none;
            border-color: var(--youtube-red);
        }

        .server-selector-container {
            display: none;
            margin-bottom: 12px;
            background-color: var(--youtube-gray);
            border-radius: var(--radius);
            padding: 12px;
        }
        .server-selector-container h3 {
            font-size: 16px;
            color: var(--light);
            margin-bottom: 8px;
            font-weight: 600;
        }
        .server-selector {
            width: 100%;
            padding: 10px 12px;
            border-radius: 4px;
            background-color: var(--youtube-dark-gray);
            border: 1px solid var(--dark-3);
            color: var(--light);
            font-size: 14px;
            cursor: pointer;
        }
        .server-selector:focus {
            outline: none;
            border-color: var(--youtube-red);
        }

        .season-selector {
            display: none; /* JS handles visibility */
            margin-bottom: 20px; /* Consistent margin */
            background-color: var(--youtube-gray);
            border-radius: var(--radius);
            padding: 15px;
        }

        .season-selector h3 {
            font-size: 16px;
            color: var(--light);
            margin-bottom: 10px;
            font-weight: 600;
        }

        .seasons-grid {
            display: grid;
            /* Adjust columns for sidebar context if moved, or make it scrollable horizontally */
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); /* Smaller cards */
            gap: 10px;
            max-height: 300px; /* Example: limit height and make scrollable if many seasons */
            overflow-y: auto; /* Add scroll if content overflows */
        }

        .season-card {
            background-color: var(--youtube-dark-gray); /* Consistent with episode selector */
            border-radius: 4px; /* Sharper radius */
            overflow: hidden;
            cursor: pointer;
            transition: var(--transition);
            border: 1px solid var(--dark-3); /* Subtle border */
        }

        .season-card:hover {
            transform: translateY(-3px); /* Subtle hover */
            border-color: var(--youtube-red);
            box-shadow: 0 2px 8px rgba(0,0,0,0.3);
        }

        .season-card img {
    display: block; /* Ensure block behavior */
            width: 100%;
    height: 140px; /* Increased base height */
            object-fit: cover;
        }

        .season-info {
            padding: 10px; /* Adjusted padding */
            text-align: left; /* Align text left for better readability */
        }

        .season-info h4 {
            font-size: 13px; /* Smaller font */
            color: var(--light);
            margin: 0;
            line-height: 1.3;
             display: -webkit-box;
            -webkit-box-orient: vertical;
            -webkit-line-clamp: 2;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Styling for scrollbars in season/episode lists and sidebar */
        .seasons-grid::-webkit-scrollbar,
        .related-grid::-webkit-scrollbar,
        .related-videos::-webkit-scrollbar { /* Added .related-videos for consistent sidebar scrollbar */
            width: 8px;
        }
        .seasons-grid::-webkit-scrollbar-track,
        .related-grid::-webkit-scrollbar-track,
        .related-videos::-webkit-scrollbar-track {
            background: var(--youtube-dark-gray); /* Match sidebar background */
            border-radius: 4px;
        }
        .seasons-grid::-webkit-scrollbar-thumb,
        .related-grid::-webkit-scrollbar-thumb,
        .related-videos::-webkit-scrollbar-thumb {
            background-color: var(--youtube-light-gray);
            border-radius: 4px;
            border: 2px solid var(--youtube-dark-gray); /* Creates a 'track' around the thumb */
        }
        .seasons-grid::-webkit-scrollbar-thumb:hover,
        .related-grid::-webkit-scrollbar-thumb:hover,
        .related-videos::-webkit-scrollbar-thumb:hover {
            background-color: var(--youtube-red);
        }

        .related-videos {
            /* width: 400px; No longer fixed width, grid handles it */
            min-width: 0; /* Allow shrinking in grid */
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        /* Removed max-width: 1200px media query for .related-videos width: 100%
           as grid layout handles this better. */

        .related-title {
            margin: 0 0 15px; /* Adjusted margin */
            font-size: 18px; /* Slightly smaller title for sidebar */
            color: var(--light);
            font-weight: 600;
        }

        .related-grid {
            display: flex;
            flex-direction: column;
            gap: 12px; /* Adjusted gap */
        }

        .related-card {
            display: flex;
            gap: 10px; /* Adjusted gap */
            cursor: pointer;
            transition: var(--transition);
            padding: 8px; /* Add some padding for hover effect area */
            border-radius: var(--radius);
        }

        .related-card:hover {
            /* border-radius is already set */
        }

        @property --angle {
            syntax: '<angle>';
            initial-value: 0deg;
            inherits: false;
        }

        @keyframes spin {
            to {
                --angle: 360deg;
            }
        }

        .content-card, .related-card {
            border: 2px solid transparent;
            background: transparent;
            position: relative;
            z-index: 1;
        }

        .content-card::after, .related-card::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: var(--youtube-gray);
            border-radius: inherit;
            z-index: -1;
        }

        .content-card::before, .related-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            z-index: -2;
            margin: -2px;
            border-radius: inherit;
            background: conic-gradient(from var(--angle), var(--primary-dark), var(--primary), var(--primary-dark));
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .content-card:hover::before, .related-card:hover::before {
            opacity: 1;
            animation: spin 2.5s linear infinite;
        }

        .related-thumbnail {
            width: 160px; /* Adjusted for sidebar */
            height: 90px;  /* Maintain 16:9 approx ratio */
            border-radius: 4px; /* Smaller radius */
            overflow: hidden;
            flex-shrink: 0;
        }

        .related-thumbnail img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .related-info {
            flex: 1;
        }

        .related-info h4 {
            font-size: 14px;
            margin-bottom: 5px;
            color: var(--light);
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .related-channel {
            font-size: 13px;
            color: var(--youtube-light-gray);
            margin-bottom: 5px;
        }

        .related-meta { /* This class is no longer directly used for the main details, but might be used by other elements or future code */
            font-size: 12px;
            color: var(--youtube-light-gray);
        }

        .related-meta-stars {
            margin-bottom: 4px; /* Space between stars and details */
        }
        .related-meta-stars .star-rating { /* Ensure stars in related content are sized appropriately */
            font-size: 13px; 
        }
        .related-meta-stars .star-rating .fas.fa-star,
        .related-meta-stars .star-rating .fas.fa-star-half-alt {
            color: gold;
        }
        .related-meta-stars .star-rating .far.fa-star {
            color: #ccc;
        }

        .related-meta-details .meta-netflix-style {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0; 
            color: var(--youtube-light-gray);
            font-size: 12px; /* Slightly smaller font for related items */
        }

        .related-meta-details .meta-netflix-style span:not(:empty):not(:last-child)::after {
            content: "·";
            margin: 0 0.4em;
            color: var(--youtube-light-gray);
        }
        .related-meta-details .meta-netflix-style span:empty {
            display: none;
        }

        .related-meta-details .meta-netflix-style .meta-country {
            display: inline-flex;
            align-items: center;
            gap: 0.3em;
        }
        .related-meta-details .meta-netflix-style .meta-country .fi {
             /* Uses default flag icon size, adjust if needed */
        }

        .related-channel {
            font-size: 13px; /* Existing style */
            color: var(--youtube-light-gray); /* Existing style */
            margin-top: 6px; /* Add some space above the channel name */
        }

        .close-viewer {
            position: absolute;
            top: 20px;
            right: 20px;
            background: none;
            border: none;
            color: var(--light);
            font-size: 24px;
            cursor: pointer;
            z-index: 10;
        }

        .back-button {
            display: flex;
            align-items: center;
            gap: 10px;
            background: var(--youtube-gray);
            border: none;
            color: var(--light);
            padding: 8px 16px;
            border-radius: 20px;
            cursor: pointer;
            margin-bottom: 10px;
            transition: var(--transition);
        }

        .back-button:hover {
            background: var(--youtube-red);
        }

        /* Mobile-specific back button styling */
        @media (max-width: 768px) {
            .back-button {
                padding: 12px 20px;
                font-size: 16px;
                min-height: 48px; /* Touch-friendly size */
                border-radius: 24px;
                margin: 10px;
                box-shadow: 0 2px 8px rgba(0,0,0,0.3);
            }
            
            .back-button:active {
                transform: scale(0.95);
                transition: transform 0.1s ease;
            }

            .mobile-back-hint {
                display: inline-block !important;
                font-size: 12px;
                opacity: 0.8;
                margin-left: 8px;
            }

            .back-text {
                display: none;
            }
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.9);
            z-index: 2000;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background-color: var(--dark-2);
            border-radius: var(--radius);
            width: 90%;
            max-width: 800px;
            max-height: 90vh;
            overflow: auto;
            position: relative;
            box-shadow: var(--shadow);
        }

        .modal-header {
            padding: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--dark-3);
        }

        .modal-title {
            font-size: 24px;
            font-weight: 700;
        }

        .close-modal {
            background: none;
            border: none;
            color: var(--light);
            font-size: 24px;
            cursor: pointer;
            transition: var(--transition);
        }

        .close-modal:hover {
            color: var(--primary);
        }

        .modal-body {
            padding: 20px;
        }

        /* Parental Controls Styles */
        .parental-controls-content, .pin-entry-content {
            max-width: 500px;
        }
        .ratings-select-content {
            max-width: 400px;
        }
        .parental-control-pin-section {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--dark-3);
        }
        .pin-display-container {
            margin: 20px 0;
        }
        .pin-display, .pin-display-input {
            display: flex;
            justify-content: center;
            gap: 15px;
            margin-bottom: 10px;
        }
        .pin-display .pin-dot, .pin-display-input .pin-dot {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background-color: var(--dark-3);
            border: 2px solid var(--gray);
            transition: all 0.2s ease;
        }
        .pin-display .pin-dot.filled, .pin-display-input .pin-dot.filled {
            background-color: var(--youtube-red);
            border-color: var(--youtube-red);
        }
        .pin-status-text, .pin-status-text-input {
            min-height: 20px;
            color: var(--youtube-light-gray);
        }
        .pin-pad, .pin-pad-input {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            max-width: 300px;
            margin: 20px auto;
        }
        .pin-pad .pin-btn, .pin-pad-input .pin-btn {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            border: none;
            background-color: var(--youtube-red);
            color: white;
            font-size: 28px;
            font-weight: 700;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .pin-pad .pin-btn:hover, .pin-pad-input .pin-btn:hover {
            background-color: var(--primary-dark);
            transform: scale(1.05);
        }
        .pin-pad .pin-btn.backspace, .pin-pad-input .pin-btn.backspace {
            background-color: transparent;
            font-size: 24px;
        }
        .parental-control-setting-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 0;
            border-bottom: 1px solid var(--dark-3);
        }
        .parental-control-setting-item:last-child {
            border-bottom: none;
        }
        .parental-control-setting-item h4 {
            margin: 0 0 5px;
            font-size: 16px;
            color: var(--light);
        }
        .parental-control-setting-item p {
            margin: 0;
            font-size: 14px;
            color: var(--gray);
        }
        .ratings-summary {
            font-style: italic;
            margin-top: 5px !important;
            font-size: 13px !important;
            color: var(--youtube-light-gray) !important;
            max-width: 250px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .parental-btn, .parental-btn-secondary {
            padding: 10px 20px;
            border-radius: var(--radius);
            border: none;
            cursor: pointer;
            font-weight: 600;
            transition: var(--transition);
        }
        .parental-btn {
            background-color: var(--youtube-red);
            color: white;
        }
        .parental-btn:hover {
            background-color: var(--primary-dark);
        }
        .parental-btn-secondary {
            background-color: var(--youtube-gray);
            color: var(--light);
        }
        .parental-btn-secondary:hover {
            background-color: var(--dark-3);
        }

        /* Toggle Switch */
        .switch {
            position: relative;
            display: inline-block;
            width: 60px;
            height: 34px;
            flex-shrink: 0;
        }
        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: var(--dark-3);
            transition: .4s;
        }
        .slider:before {
            position: absolute;
            content: "";
            height: 26px;
            width: 26px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
        }
        input:checked + .slider {
            background-color: var(--youtube-red);
        }
        input:focus + .slider {
            box-shadow: 0 0 1px var(--youtube-red);
        }
        input:checked + .slider:before {
            transform: translateX(26px);
        }
        .slider.round {
            border-radius: 34px;
        }
        .slider.round:before {
            border-radius: 50%;
        }

        /* Ratings Select Modal */
        .ratings-select-content .modal-body {
            max-height: 50vh;
            overflow-y: auto;
        }
        #ratings-checkbox-container {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        .rating-category-title {
            font-weight: 700;
            font-size: 18px;
            color: var(--youtube-red);
            margin-top: 10px;
            margin-bottom: 5px;
            padding-bottom: 5px;
            border-bottom: 1px solid var(--dark-3);
        }
        .rating-checkbox-label {
            display: block;
            position: relative;
            padding-left: 35px;
            cursor: pointer;
            font-size: 16px;
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
            user-select: none;
        }
        .rating-checkbox-label input {
            position: absolute;
            opacity: 0;
            cursor: pointer;
            height: 0;
            width: 0;
        }
        .checkmark {
            position: absolute;
            top: 0;
            left: 0;
            height: 25px;
            width: 25px;
            background-color: var(--dark-3);
            border-radius: 4px;
            border: 1px solid var(--gray);
        }
        .rating-checkbox-label:hover input ~ .checkmark {
            background-color: var(--dark-2);
        }
        .rating-checkbox-label input:checked ~ .checkmark {
            background-color: var(--youtube-red);
            border-color: var(--youtube-red);
        }
        .checkmark:after {
            content: "";
            position: absolute;
            display: none;
        }
        .rating-checkbox-label input:checked ~ .checkmark:after {
            display: block;
        }
        .rating-checkbox-label .checkmark:after {
            left: 9px;
            top: 5px;
            width: 5px;
            height: 10px;
            border: solid white;
            border-width: 0 3px 3px 0;
            -webkit-transform: rotate(45deg);
            -ms-transform: rotate(45deg);
            transform: rotate(45deg);
        }
        .ratings-select-content .modal-footer {
            padding: 20px;
            display: flex;
            justify-content: flex-end;
            gap: 15px;
            border-top: 1px solid var(--dark-3);
        }

        /* PIN Entry Modal */
        .pin-entry-content .modal-body {
            text-align: center;
        }
        .pin-entry-actions {
            display: flex;
            justify-content: space-evenly;
            margin-top: 20px;
        }

        /* Footer */
        footer {
            background-color: var(--youtube-dark);
            padding: 30px 5%;
            border-top: 1px solid var(--youtube-gray);
        }

        .footer-content {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 30px;
            margin-bottom: 30px;
        }

        .footer-logo {
            font-size: 28px;
            font-weight: 700;
            color: var(--youtube-red);
            margin-bottom: 15px;
        }

        .footer-about {
            text-align: center;
        }

        .footer-title {
            font-size: 18px;
            margin-bottom: 20px;
            color: var(--light);
        }

        .footer-links {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .footer-links a {
            color: var(--youtube-light-gray);
            text-decoration: none;
            transition: var(--transition);
        }

        .footer-links a:hover {
            color: var(--youtube-red);
        }

        .social-links {
            display: flex;
            gap: 15px;
            margin-top: 15px;
        }

        .social-links a {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: var(--youtube-gray);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--light);
            transition: var(--transition);
        }

        .social-links a:hover {
            background-color: var(--youtube-red);
            transform: translateY(-5px);
        }

        .download-button {
            display: inline-block;
            padding: 15px 25px;
            font-size: 18px;
            font-weight: bold;
            color: #fff;
            background-color: #e50914;
            border: none;
            border-radius: 5px;
            text-decoration: none;
            position: relative;
            overflow: hidden;
            transition: background-color 0.3s;
        }

        .download-button::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 300px;
            height: 300px;
            background-color: rgba(255, 255, 255, 0.15);
            border-radius: 50%;
            transform: translate(-50%, -50%) scale(0);
            transition: transform 0.5s;
        }

        .download-button:hover::before {
            transform: translate(-50%, -50%) scale(2);
        }

        .download-button:hover {
            background-color: #b20710;
        }

        .download-image {
            margin-top: 20px;
            max-width: 100%;
            height: auto;
            border-radius: 10px;
        }

        .adsense-banner {
            height: 120px;
            background: linear-gradient(45deg, #1a2a6c, #b21f1f, #1a2a6c);
            border-radius: var(--radius);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 30px 0;
            color: white;
            font-weight: 700;
            font-size: 20px;
            letter-spacing: 1px;
        }

        .footer-bottom {
            text-align: center;
            padding-top: 20px;
            border-top: 1px solid var(--youtube-gray);
            color: var(--youtube-light-gray);
            font-size: 14px;
        }

        /* Performance Optimizations */
        .load-more-container {
            display: flex;
            justify-content: center;
            margin-top: 30px;
        }

        .load-more-btn {
            background-color: var(--youtube-red);
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 30px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
        }

        .load-more-btn:hover {
            background-color: var(--primary-dark);
            transform: translateY(-3px);
        }

        .virtual-scroll-container {
            position: relative;
            height: calc(100vh - 300px);
            overflow-y: auto;
        }

        .virtual-scroll-content {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
        }

        /* Loading Spinner */
        .loading-spinner {
            display: none;
            width: 50px;
            height: 50px;
            border: 5px solid rgba(255,255,255,0.3);
            border-radius: 50%;
            border-top-color: var(--youtube-red);
            animation: spin 1s ease-in-out infinite;
            margin: 30px auto;
        }

        /* Progress Bar */
        .progress-bar-container {
            width: 80%;
            max-width: 600px;
            margin: 30px auto;
        }

        .progress-bar-text {
            text-align: center;
            margin-bottom: 10px;
            color: var(--light-2);
            font-size: 14px;
        }

        .progress-bar {
            width: 0%;
            height: 20px;
            background-color: var(--youtube-red);
            border-radius: var(--radius);
            transition: width 0.2s ease-out;
            box-shadow: inset 0 1px 3px rgba(0,0,0,0.2);
        }

        /* Pagination Styles */
        .pagination-container {
            display: flex;
            justify-content: center;
            align-items: center;
            margin-top: 30px;
        }

        .pagination-btn {
            background-color: var(--youtube-gray);
            color: white;
            border: none;
            padding: 10px 15px;
            margin: 0 5px;
            border-radius: 5px;
            cursor: pointer;
            transition: var(--transition);
        }

        .pagination-btn:hover {
            background-color: var(--youtube-red);
        }

        .pagination-btn.active {
            background-color: var(--youtube-red);
        }

        .pagination-btn:disabled {
            background-color: var(--dark-3);
            cursor: not-allowed;
        }

        @media (max-width: 576px) {
            .pagination-btn:not(:first-child):not(:last-child) {
                display: none;
            }
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Responsive Styles */
        @media (max-width: 1200px) {
            /* .viewer-content grid adjustment already handled by min-width: 992px for multi-column */

            .carousel {
                height: 350px;
            }

            /* .related-videos width: 100%; No longer needed due to grid */
        }

        @media (max-width: 991px) { /* Changed from 992px to target below desktop grid */
            .viewer-content {
                grid-template-columns: 1fr; /* Stack columns for tablets and mobiles */
            }
            .related-videos {
                 margin-top: 20px; /* Reduced margin when stacked for tighter layout */
            }
            .carousel {
                height: 300px;
            }

            .content-grid {
                grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            }

            .search-container {
                width: 50%;
            }

            .player-container {
                /* Ensure player maintains aspect ratio and doesn't get too small */
                min-height: 200px; /* Example minimum height for mobile */
            }

            .video-meta {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }

            .video-actions {
                width: 100%;
                justify-content: space-between;
            }
        }

        @media (max-width: 768px) {
            .carousel {
                height: 250px;
            }

            .content-grid {
                grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            }

            .search-container {
                width: 60%;
            }

            .header-controls {
                gap: 15px;
            }

            .carousel-content h2 {
                font-size: 1.8rem;
            }

            .carousel-content p {
                font-size: 14px;
            }

            /* Mobile search visibility */
            .search-container {
                position: relative;
                width: 100%;
                margin-top: 10px;
            }

            .mobile-search-btn {
                display: none;
            }

            .search-results {
                max-height: 200px;
            }

            .search-container input {
                background-color: rgba(0,0,0,0.9);
            }

            .search-input-container {
                width: 100%;
            }

            .related-thumbnail {
                width: 140px;
                height: 80px;
            }
        }

        @media (max-width: 576px) {
            .logo {
                font-size: 22px;
            }

            .carousel {
                height: 200px;
                margin-top: 20px;
            }

            .carousel-content {
                padding: 15px;
            }

            .carousel-content h2 {
                font-size: 1.4rem;
            }

            .carousel-content p {
                display: none;
            }

            .filters-row {
                flex-direction: column;
            }

            .view-toggle {
                margin-top: 15px;
            }

            .content-grid {
                grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
                gap: 15px;
            }

            .related-thumbnail {
                width: 100px;
            }

            .video-title {
                font-size: 18px;
            }
        }

        @media (max-width: 576px) {
            .content-card.list {
                height: 140px; /* Fixed height for consistent card size */
                align-items: center; /* Vertically align card-img and card-info */
                overflow: hidden; /* Clip content that might overflow the fixed height */
            }

            .content-card.list .card-img {
                width: 80px;  /* New smaller width for mobile */
                height: 120px; /* New smaller height for mobile, maintains 2:3 aspect ratio */
                flex-shrink: 0; /* Prevent image container from shrinking */
                /* background-color: red; --- REMOVED DEBUG --- */
            }

            .content-card.list .card-img img {
                /* background-color: blue; --- REMOVED DEBUG --- */
                /* Ensure other img styles are still considered if this overrides the global one */
                display: block;
                width: 100%; /* This will be 80px */
                height: 120px !important; /* Explicitly set height, using !important for emphasis - assess if !important still needed */
                object-fit: cover;
            }

            .content-card.list .card-info {
                padding: 10px 15px; /* Adjust padding for smaller card layout */
                display: flex;
                flex-direction: column;
                justify-content: center; /* Helps align content vertically if card is taller */
            }

            .content-card.list .card-title {
                font-size: 15px; /* Slightly smaller title */
                -webkit-line-clamp: 2;
                max-height: calc(15px * 1.4 * 2); /* Corresponds to 2 lines */
                overflow: hidden;
                text-overflow: ellipsis;
                /* display: -webkit-box; and -webkit-box-orient: vertical; are global */
            }

            .content-card.list .card-meta {
                font-size: 12px; /* Slightly smaller meta */
                margin-top: 4px; /* Space between title and meta */
            }

            .content-card.list .card-description {
                font-size: 13px;
                line-height: 1.4;
                display: -webkit-box;
                -webkit-box-orient: vertical;
                -webkit-line-clamp: 2; /* Limit to 2 lines */
                overflow: hidden;
                text-overflow: ellipsis;
                margin-top: 4px; /* Space between meta and description */
                /* max-height: calc(13px * 1.4 * 2); /* Fallback for non-webkit */
            }
        }

        /* Smart TV and Large Screens */
        @media (min-width: 1600px) {
            .carousel {
                height: 600px;
            }

            .carousel-content h2 {
                font-size: 3.5rem;
            }

            .carousel-content p {
                font-size: 1.2rem;
                max-width: 800px;
            }

            .content-grid {
                grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
                gap: 40px;
            }

            .section-title {
                font-size: 32px;
            }

            .filter-select {
                padding: 15px 20px;
                font-size: 18px;
            }

            .viewer-content {
                grid-template-columns: 3fr 1fr;
                height: calc(100vh - 250px);
            }

            .video-title {
                font-size: 28px;
            }

            .video-description {
                font-size: 18px;
            }

            .server-btn, .episode-selector {
                font-size: 18px;
                padding: 12px 20px;
            }

            .related-title {
                font-size: 24px;
            }

            .season-card img {
                height: 240px; /* Increased height for very large screens */
            }

            .related-videos {
                max-height: 90vh;
                overflow-y: auto;
            }
        }

        .mobile-search-btn {
            display: none;
        }

/* Search Visibility: Default to hidden on mobile, shown on desktop */
/* Desktop: .search-container is display:flex by default */

@media (max-width: 768px) {
  .search-container {
    display: none; /* Hidden by default on mobile */
    flex-direction: column; /* Adjust layout for mobile if needed when shown */
    width: 100%; /* Full width on mobile when shown */
    position: absolute; /* Position it over content */
    top: var(--header-height); /* Below the header */
    left: 0;
    background-color: var(--youtube-dark); /* Or a slightly different shade for dropdown */
    padding: 10px;
    z-index: 1001; /* Above other header items, below modal */
    box-shadow: var(--shadow);
  }

  .search-container.show {
    display: flex !important; /* Use flex when shown on mobile */
  }

  .search-container input {
    width: 100%; /* Full width input on mobile */
  }

  .mobile-search-btn {
    display: flex !important; /* Show mobile search button */
    align-items: center;
    justify-content: center;
  }

  .user-profile {
      display: none;
  }

    .hamburger-btn {
        display: block;
    }

    .mobile-filters-menu {
        display: none;
        position: absolute;
        top: var(--header-height);
        left: 0;
        width: 100%;
        background-color: var(--youtube-dark);
        padding: 20px;
        z-index: 999;
        box-shadow: var(--shadow);
    }

    .mobile-filters-menu.show {
        display: block;
    }
}

/* Desktop-specific if any further overrides needed, but base style should cover it. */
/* @media (min-width: 769px) { ... } */

/* Default state for mobile search button (hidden on desktop) */
.mobile-search-btn {
  display: none; /* Hidden by default, overridden by media query for mobile */
  background: var(--youtube-red);
  color: #fff;
  width: 40px;
  height: 40px;
  border: none;
  border-radius: 50%;
  font-size: 18px;
  cursor: pointer;
  box-shadow: 0 3px 8px rgba(0, 0, 0, 0.25);
  display: flex;
  align-items: center;
  justify-content: center;
  transition: background 0.3s ease, transform 0.2s ease;
}

.mobile-search-btn:hover {
  background: var(--primary-dark);
  transform: scale(1.05);
}

@media (max-width: 480px) {
  .content-card.grid .card-info {
    min-height: 100px;
    justify-content: flex-start; /* Align content to top */
  }

  .content-card.grid .card-meta {
    /* display: none; */ /* Hide meta for more title space on small mobile grid - Overridden to show meta */
    display: flex !important; /* Ensure it's shown and stacked, overriding any other display properties. !important to be sure. */
    flex-direction: column;
    align-items: center;
    gap: 4px; /* Consistent with other grid meta styling */
  }

          /* General card title adjustments for very small screens */
          .card-title { /* Applies to both grid and list unless overridden */
    font-size: 13px;
            -webkit-line-clamp: 2; /* Ensure 2 lines */
            /* height was !important, use max-height if possible for flexibility */
            max-height: calc(13px * 1.4 * 2);
            overflow: hidden !important; /* Retained from previous fix, but check if needed */
          }
          /* Specific override for list view title if needed, or let general rule apply */
          .content-card.list .card-title {
             font-size: 14px; /* Example: slightly larger than grid title on smallest screens */
             max-height: calc(14px * 1.4 * 2);
  }


  .card-meta { /* This rule still applies to .content-card.list .card-meta */
            font-size: 11px; /* Already set */
             margin-top: 3px; /* Adjust margin */
  }
           .content-card.list .card-meta { /* Specific for list view on smallest screens */
               font-size: 11px;
           }


  .content-card.list .card-description {
    font-size: 12px; /* Adjust description font size for small screens */
    line-height: 1.4; /* Ensure good line spacing for readability */
    display: -webkit-box;
    -webkit-box-orient: vertical;
            -webkit-line-clamp: 2; /* Limit to 2 lines */
    overflow: hidden;
    text-overflow: ellipsis;
            margin-top: 3px; /* Adjust margin */
            /* max-height: calc(12px * 1.4 * 2); /* Fallback for non-webkit */
    /* Commented out trailing slash from merge error: */
  }
  /* Merged from second @media (max-width: 480px) block */
  .content-card.grid .card-info { /* This was already defined, ensuring it's the same or merged correctly */
    min-height: 100px;
    justify-content: flex-start; /* Align content to top */
  }

  .content-card.grid .card-meta { /* This was already defined, ensuring it's the same or merged correctly */
    display: flex !important; 
    flex-direction: column;
    align-items: center;
    gap: 4px; 
  }
}

@media (max-width: 576px) {
    /* Original content from first @media (max-width: 576px) block */
    .logo {
        font-size: 22px;
    }
    .carousel {
        height: 200px;
        margin-top: 20px;
    }
    .carousel-content {
        padding: 15px;
    }
    .carousel-content h2 {
        font-size: 1.4rem;
    }
    .carousel-content p {
        display: none;
    }
    .filters-row {
        flex-direction: column;
    }
    .view-toggle {
        margin-top: 15px;
    }
    .content-grid {
        grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
        gap: 15px;
    }
    .related-thumbnail {
        width: 100px;
    }
    .video-title { /* This was for .video-title general */
        font-size: 18px;
    }
     .content-card.list {
        height: 140px; 
        align-items: center; 
        overflow: hidden; 
    }
    .content-card.list .card-img {
        width: 80px;  
        height: 120px; 
        flex-shrink: 0; 
    }
    .content-card.list .card-img img {
        display: block;
        width: 100%; 
        height: 120px !important; 
        object-fit: cover;
    }
    .content-card.list .card-info {
        padding: 10px 15px; 
        display: flex;
        flex-direction: column;
        justify-content: center; 
    }
    .content-card.list .card-title {
        font-size: 15px; 
        -webkit-line-clamp: 2;
        max-height: calc(15px * 1.4 * 2); 
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .content-card.list .card-meta {
        font-size: 12px; 
        margin-top: 4px; 
    }
    .content-card.list .card-description { /* This was already defined in another @media (max-width: 576px) block, ensure it's consistent or merged */
        font-size: 13px;
        line-height: 1.4;
        display: -webkit-box;
        -webkit-box-orient: vertical;
        -webkit-line-clamp: 2; 
        overflow: hidden;
        text-overflow: ellipsis;
        margin-top: 4px; 
    }

    /* Merged from second @media (max-width: 576px) block */
    .back-button {
        padding: 12px 20px; /* Increased padding for easier tapping */
        font-size: 16px;    /* Slightly larger font for visibility */
    }
}




        /* Download Options Modal Styles */
        #downloadOptionsModal .modal-content {
            background-color: var(--youtube-dark-gray); /* Using a variable from the existing theme */
            color: var(--light);
            padding: 25px;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            width: 90%;
            max-width: 550px; /* Slightly wider for better link display */
            position: relative; /* For absolute positioning of close button */
            text-align: left; /* Align text to the left for list items */
        }

        #downloadOptionsModal .modal-header {
            display: flex;
            justify-content: space-between; /* Pushes title and close button apart */
            align-items: center;
            border-bottom: 1px solid var(--youtube-gray);
            padding-bottom: 15px;
            margin-bottom: 20px;
        }

        #downloadModalTitle {
            font-size: 22px; /* Slightly larger title */
            font-weight: 600;
            margin: 0; /* Remove default margin */
            color: var(--light); /* Ensure title color matches theme */
        }

        #closeDownloadModalBtn {
            background: none;
            border: none;
            color: var(--youtube-light-gray);
            font-size: 30px; /* Larger close button */
            cursor: pointer;
            padding: 0 5px; /* Add some padding for easier clicking */
            line-height: 1; /* Ensure it aligns well */
            position: absolute; /* Position relative to modal-content */
            top: 10px;
            right: 15px;
        }
        #closeDownloadModalBtn:hover {
            color: var(--youtube-red);
        }

        #downloadModalBody ul {
            list-style-type: none;
            padding: 0;
            margin: 0;
            max-height: 350px; /* Increased max height */
            overflow-y: auto;
        }

        #downloadModalBody li {
            margin-bottom: 12px; /* Spacing between links */
        }

        #downloadModalBody li a { /* Target the anchor within the list item */
            display: block; /* Make links take full width */
            padding: 12px 18px; /* Increased padding */
            background-color: var(--youtube-red);
            color: white !important; /* Ensure text is white */
            text-decoration: none !important; /* Ensure no underline */
            border-radius: var(--radius);
            transition: background-color 0.3s ease, transform 0.2s ease;
            font-size: 16px; /* Clearer font size */
            text-align: center; /* Center text in button */
            border: none; /* Remove any default anchor border */
        }

        #downloadModalBody li a:hover { /* Hover state for the anchor */
            background-color: var(--primary-dark); /* Using a theme variable */
            transform: translateY(-2px); /* Subtle hover effect */
            color: white !important;
        }

        /* Custom Next/Previous Episode Buttons Styling for Plyr */
        #prev-episode-btn, #next-episode-btn {
            padding: 0; 
            width: auto; 
            margin-right: 5px; /* Space before next control (e.g., settings) */
            /* display: none; /* Initially hidden, JS will manage visibility */
        }

        /* Icon styling within buttons - Plyr's default icon styling should apply if icons are standard */
        /* #prev-episode-btn i, #next-episode-btn i {} */

        /* Disabled state for custom buttons */
        #prev-episode-btn:disabled, #next-episode-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* Optional: Custom hover effect if needed, otherwise Plyr's default hover on .plyr__control will apply */
        /*
        #prev-episode-btn:not(:disabled):hover, #next-episode-btn:not(:disabled):hover {
            background: var(--youtube-red); 
            color: #fff; 
        }
        */

        .hamburger-btn {
            display: none;
            background: none;
            border: none;
            color: var(--light);
            font-size: 22px;
            cursor: pointer;
        }

        @media (max-width: 768px) {
            .user-profile {
                display: none;
            }

            .hamburger-btn {
                display: block;
            }

            .mobile-filters-menu {
                display: none;
                position: absolute;
                top: var(--header-height);
                left: 0;
                width: 100%;
                background-color: var(--youtube-dark);
                padding: 20px;
                z-index: 999;
                box-shadow: var(--shadow);
            }

            .mobile-filters-menu.show {
                display: block;
            }
        }

        /* Bottom Navigation Bar Styles */
        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            width: 100%;
            background-color: var(--youtube-dark);
            display: flex;
            justify-content: space-around;
            padding: 10px 0;
            box-shadow: 0 -2px 5px rgba(0,0,0,0.2);
            z-index: 1000;
        }

        .nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            color: var(--gray);
            text-decoration: none;
            font-size: 12px;
            transition: color 0.3s ease;
        }

        .nav-item i {
            font-size: 20px;
            margin-bottom: 5px;
        }

        .nav-item.active, .nav-item:hover {
            color: var(--youtube-red);
        }

        

    </style>
    <style id="server-selector-mobile-css">
        @media (max-width: 768px) {
            #universal-server-selector {
                min-width: 120px !important;
                max-width: 52vw !important;
                max-height: 32vh !important;
                padding: 6px !important;
                top: 6px !important;
                right: 6px !important;
                overflow-y: auto !important;
                overflow-x: hidden !important;
                overscroll-behavior: contain;
            }
            #universal-server-selector .server-item {
                padding: 4px 6px !important;
                font-size: 10px !important;
                line-height: 1.2 !important;
            }
            #universal-server-selector > div:first-child {
                font-size: 12px !important;
                margin-bottom: 8px !important;
                padding-bottom: 6px !important;
            }
            #embedded-server-selector {
                min-width: 120px !important;
                max-width: 56vw !important;
                max-height: 34vh !important;
                padding: 6px !important;
                top: 6px !important;
                right: 6px !important;
                overflow-y: auto !important;
                overflow-x: hidden !important;
                overscroll-behavior: contain;
            }
            #embedded-server-selector .server-item {
                padding: 4px 6px !important;
                font-size: 10px !important;
                line-height: 1.2 !important;
            }
            #server-selector-indicator {
                font-size: 10px !important;
                padding: 4px 8px !important;
                left: 6px !important;
                top: 6px !important;
            }
        }
        @media (max-width: 480px) {
            #universal-server-selector {
                max-width: 50vw !important;
                max-height: 28vh !important;
            }
            #embedded-server-selector {
                max-width: 52vw !important;
                max-height: 30vh !important;
            }
        }
    </style>
</head>
<body>
    <!-- Notification Bar -->
    <div class="notification-bar" id="notification-bar">
        <p id="notification-message"></p>
        <button class="close-btn" id="close-notification">&times;</button>
    </div>

    <!-- Header -->
    <header>
        <a href="#" class="logo">
            <img src="https://movie-fcs.fwh.is/cinecraze/cinecraze.png" alt="CineCraze Logo">
            <span>CineCraze</span>
        </a>

        <div class="search-container">
            <div class="search-input-container">
                <input type="text" id="search-input" placeholder="Search movies, TV shows..." role="combobox" aria-autocomplete="list" aria-expanded="false" aria-owns="search-results" aria-haspopup="listbox">
                <button class="close-search-btn">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="search-results" id="search-results" role="listbox" aria-label="Search suggestions"></div>
        </div>

        <div class="header-controls">
            <button class="mobile-search-btn" id="mobile-search-btn">
                <i class="fas fa-search"></i>
            </button>
            <button class="theme-toggle" id="refresh-btn" title="Refresh Data">
                <i class="fas fa-sync-alt"></i>
            </button>
            <button class="theme-toggle" id="theme-toggle">
                <i class="fas fa-moon"></i>
            </button>
            <div class="user-profile">
                <i class="fas fa-user"></i>
            </div>
            <button class="hamburger-btn" id="hamburger-btn">
                <i class="fas fa-bars"></i>
            </button>
        </div>
    </header>

    <!-- Main Content -->
    <main>
        <!-- Carousel -->
        <div class="carousel">
            <div class="carousel-inner" id="carousel-inner">
                <!-- Carousel items will be dynamically added here -->
            </div>
            <div class="carousel-controls">
                <button class="carousel-btn" id="carousel-prev">
                    <i class="fas fa-chevron-left"></i>
                </button>
                <button class="carousel-btn" id="carousel-next">
                    <i class="fas fa-chevron-right"></i>
                </button>
            </div>
            <div class="carousel-indicators" id="carousel-indicators">
                <!-- Indicators will be dynamically added here -->
            </div>
        </div>

        <!-- Filters Section -->
        <div class="filters-section">
            <div class="section-header">
                <h2 class="section-title">Browse Content</h2>
                <div class="view-toggle">
                    <button class="view-btn active" id="view-toggle-btn">
                        <i class="fas fa-th"></i>
                    </button>
                </div>
            </div>

            <div class="mobile-filters-menu">
                <div class="filters-row">
                <div class="filter-group">
                    <label for="genre-filter">Genre</label>
                    <select id="genre-filter" class="filter-select">
                        <option value="all">All Genres</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="year-filter">Year</label>
                    <select id="year-filter" class="filter-select">
                        <option value="all">All Years</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="country-filter">Country</label>
                    <select id="country-filter" class="filter-select">
                        <option value="all">All Countries</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="sort-filter">Sort By</label>
                    <select id="sort-filter" class="filter-select">
                        <option value="newest">Newest First</option>
                        <option value="popular">Most Popular</option>
                        <option value="rating">Highest Rated</option>
                    </select>
                </div>
            </div>
            </div>
        </div>

        <!-- Content Grid/List -->
        <div class="content-container">
            <div class="content-grid" id="content-grid">
                <!-- Content cards will be dynamically added here -->
            </div>

            <div class="content-list" id="content-list">
                <!-- List view will be dynamically added here -->
            </div>

            <div class="content-grid" id="watch-later-grid" style="display: none;">
                <!-- Watch Later items will be dynamically added here -->
            </div>

            <!-- Loading Spinner -->
            <div class="loading-spinner" id="loading-spinner"></div>

            <!-- Progress Bar -->
            <div class="progress-bar-container" id="progress-bar-container" style="display: none;">
                <div class="progress-bar-text" id="progress-bar-text"></div>
                <div class="progress-bar" id="progress-bar"></div>
            </div>

            <!-- Pagination -->
            <div class="pagination-container" id="pagination-container">
                <!-- Pagination buttons will be dynamically added here -->
            </div>
        </div>

        <!-- Viewer Page - YouTube Style -->
        <div class="viewer-page" id="viewer-page">
            <div class="youtube-viewer-container">
                <!-- Back Button -->
                <button class="back-button" id="back-button">
                    <i class="fas fa-arrow-left"></i>
                    <span class="back-text">Back to Browse</span>
                    <span class="mobile-back-hint" style="display: none;">← Swipe or tap</span>
                </button>

                <div class="player-container">
                    <video id="player" playsinline controls crossorigin="anonymous">
                        <!-- Source will be set dynamically -->
                    </video>
                    <div id="player-message-area" style="display: none; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); text-align: center; padding: 20px; background-color: var(--youtube-gray); border-radius: var(--radius);"></div>
                </div>

                <div class="viewer-content">
                    <div class="video-details">
                        <div class="video-info">
                            <h1 class="video-title" id="viewer-title">Movie Title</h1>
                            <div class="video-meta">
                                <div class="video-stats">
                                    <span id="viewer-views"><i class="fas fa-eye"></i> 1.2M views</span>
                                    <span id="viewer-date"><i class="fas fa-calendar"></i> Jan 15, 2023</span>
                                </div>
                                <div class="video-actions">
                                    <div class="like-dislike-container">
                                        <div class="icons-box">
                                            <label class="icons">
                                                <span class="btn-label">
                                                    <input class="input-box" type="checkbox" id="like-checkbox" name="like-checkbox">
                                                    <span class="like-text-content" id="like-count-span">24K</span>
                                                    <i id="icon-like-regular" class="fas fa-thumbs-up"></i>
                                                    <i id="icon-like-solid" class="fas fa-thumbs-up"></i>
                                                    <div class="fireworks">
                                                        <div class="checked-like-fx"></div>
                                                    </div>
                                                </span>
                                            </label>
                                            <label class="icons">
                                                <span class="btn-label">
                                                    <input class="input-box" type="checkbox" id="dislike-checkbox" name="dislike-checkbox">
                                                    <i id="icon-dislike-regular" class="fas fa-thumbs-down"></i>
                                                    <i id="icon-dislike-solid" class="fas fa-thumbs-down"></i>
                                                    <span class="dislike-text-content" id="dislike-count-span">1K</span>
                                                    <div class="fireworks">
                                                        <div class="checked-dislike-fx"></div>
                                                    </div>
                                                </span>
                                            </label>
                                            <label class="icons">
                                                <span class="btn-label">
                                                    <input class="input-box" type="checkbox" id="share-checkbox" name="share-checkbox">
                                                    <i id="icon-share-regular" class="fas fa-share"></i>
                                                    <i id="icon-share-solid" class="fas fa-share"></i>
                                                    <span class="share-text-content">Share</span>
                                                    <div class="fireworks">
                                                        <div class="checked-share-fx"></div>
                                                    </div>
                                                </span>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="video-description" id="viewer-description">
                            Description will appear here...
                        </div>

                        <div class="server-selector-container" id="server-selector-container">
                            <h3>Select Server</h3>
                            <select class="server-selector" id="server-selector"></select>
                        </div>

                        <div class="episode-selector-container" id="episode-selector-container">
                            <h3>Select Episode</h3>
                            <select class="episode-selector" id="episode-selector">
                                <!-- Episode options will be dynamically added -->
                            </select>
                        </div>

                        <div class="season-selector" id="season-selector">
                            <h3>Seasons</h3>
                            <div class="seasons-grid" id="seasons-grid">
                                <!-- Season cards will be dynamically added -->
                            </div>
                        </div>
                    </div>

                    <div class="related-videos">
                        <h2 class="related-title">Related Content</h2>
                        <div class="related-grid" id="related-grid">
                            <!-- Related content will be dynamically added -->
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Bottom Navigation Bar -->
    <nav class="bottom-nav">
        <a href="#" class="nav-item active" data-category="all">
            <i class="fas fa-home"></i>
            <span>All</span>
        </a>
        <a href="#" class="nav-item" data-category="movies">
            <i class="fas fa-film"></i>
            <span>Movie</span>
        </a>
        <a href="#" class="nav-item" data-category="series">
            <i class="fas fa-tv"></i>
            <span>Series</span>
        </a>
        <a href="#" class="nav-item" data-category="live">
            <i class="fas fa-broadcast-tower"></i>
            <span>Live</span>
        </a>
        <a href="#" class="nav-item" data-category="watch-later">
            <i class="fas fa-clock"></i>
            <span>Watch Later</span>
        </a>
    </nav>
    <!-- Parental Controls Modals -->
    <div id="parental-controls-modal" class="modal">
        <div class="modal-content parental-controls-content">
            <div class="modal-header">
                <h2 class="modal-title">Parental Controls</h2>
                <button class="close-modal" id="close-parental-controls-modal">&times;</button>
            </div>
            <div class="modal-body">
                <div class="parental-control-pin-section">
                    <h3>Set a 4-digit PIN to restrict access</h3>
                    <div class="pin-display-container">
                        <div class="pin-display">
                            <span class="pin-dot"></span><span class="pin-dot"></span><span class="pin-dot"></span><span class="pin-dot"></span>
                        </div>
                        <p class="pin-status-text"></p>
                    </div>
                    <div class="pin-pad">
                        <!-- PIN pad buttons will be generated by JS -->
                    </div>
                    <button id="reset-pin-btn" class="parental-btn">Reset PIN</button>
                </div>
                <div class="parental-control-setting-item">
                    <div>
                        <h4>Content Restrictions</h4>
                        <p>Select which content ratings are allowed.</p>
                        <p id="allowed-ratings-display" class="ratings-summary">All ratings allowed</p>
                    </div>
                    <button id="change-ratings-btn" class="parental-btn-secondary">Change</button>
                </div>
                <div class="parental-control-setting-item">
                    <div>
                        <h4>Unrated Content</h4>
                        <p>Allow content without a rating to be played.</p>
                    </div>
                    <label class="switch">
                        <input type="checkbox" id="unrated-content-toggle">
                        <span class="slider round"></span>
                    </label>
                </div>
            </div>
        </div>
    </div>

    <div id="ratings-select-modal" class="modal">
        <div class="modal-content ratings-select-content">
            <div class="modal-header">
                <h3 class="modal-title">Select Allowed Ratings</h3>
            </div>
            <div class="modal-body" id="ratings-checkbox-container">
                <!-- Checkboxes will be generated by JS -->
            </div>
            <div class="modal-footer">
                <button class="parental-btn-secondary" id="cancel-ratings-btn">Cancel</button>
                <button class="parental-btn" id="ok-ratings-btn">OK</button>
            </div>
        </div>
    </div>

    <div id="pin-entry-modal" class="modal">
        <div class="modal-content pin-entry-content">
             <div class="modal-header">
                <h3 class="modal-title" id="pin-entry-title">Enter Parental Control PIN</h3>
                <button class="close-modal" id="close-pin-entry-modal">&times;</button>
            </div>
            <div class="modal-body">
                <div class="pin-display-container">
                    <div class="pin-display-input">
                        <span class="pin-dot"></span><span class="pin-dot"></span><span class="pin-dot"></span><span class="pin-dot"></span>
                    </div>
                    <p class="pin-status-text-input"></p>
                </div>
                <div class="pin-pad-input">
                    <!-- PIN pad buttons will be generated by JS -->
                </div>
                <div class="pin-entry-actions">
                     <button class="parental-btn-secondary" id="cancel-pin-entry-btn">Cancel</button>
                     <button class="parental-btn" id="ok-pin-entry-btn">OK</button>
                </div>
            </div>
        </div>
    </div>
    <!-- End Parental Controls Modals -->

    <!-- Footer -->
    <footer>
        <div class="footer-content">
            <div class="footer-about">
                <div class="download-section">
                    <a href="https://github.com/MovieAddict88/Movie-Source/raw/main/CineCraze.apk" class="download-button">Download CineCraze App</a>
                    <img src="https://raw.githubusercontent.com/MovieAddict88/Movie-Source/main/cinecraze.png" alt="CineCraze App" class="download-image">
                </div>
                <p>Your ultimate destination for unlimited movies, TV shows, and live television. Stream anytime, anywhere on all your devices.</p>
                <div class="social-links">

<a href="https://www.facebook.com/fagmmmu" target="_blank"><i class="fab fa-facebook-f"></i></a>
<a href="https://www.tiktok.com/@ronaldlimpiadotor?_t=ZS-8xqYEXpLMme&_r=1" target="_blank"><i class="fab fa-tiktok"></i></a>
<a href="https://youtube.com/@ronaldtorrejos?si=z1du-MZnP6OyUmlf" target="_blank"><i class="fab fa-youtube"></i></a>


                </div>
            </div>



            <div class="footer-links-group">
                <h3 class="footer-title">Support</h3>
                <div class="footer-links">
                    <a href="https://movie-fcs.fwh.is/CineMax/contact.html" target="_blank">Contact Us</a>
                    <a href="https://movie-fcs.fwh.is/CineMax/faq.html" target="_blank">FAQ</a>
                    <a href="https://movie-fcs.fwh.is/CineMax/help.html" target="_blank">Help Center</a>
                    <a href="https://movie-fcs.fwh.is/CineMax/terms.html" target="_blank">Terms of Service</a>
                    <a href="https://movie-fcs.fwh.is/CineMax/policy.html" target="_blank">Privacy Policy</a>
                    <a href="#" id="parental-controls-link">Parental Controls</a>
                </div>
            </div>

            <div class="footer-links-group">
                <h3 class="footer-title">This is For Sale Just Contact Me</h3>
                <div class="footer-links">
                    <a href="mailto:ronatorrejos1@gmail.com">
  <i class="fas fa-envelope"></i> ronatorrejos1@gmail.com
</a>
                    <a href="tel:+639663016917">
  <i class="fas fa-phone"></i> +639663016917
</a>
                    <a href="#"><i class="fas fa-map-marker-alt"></i> Cagayan de Oro City 6000, Philippines</a>
                </div>
            </div>
        </div>

        <div class="adsense-banner">
            ADVERTISEMENT
        </div>

        <div class="footer-bottom">
            &copy; 2025 CineCraze. All Rights Reserved.
        </div>
    </footer>

    <script src="https://cdn.plyr.io/3.7.8/plyr.js"></script>
    <script src="https://cdn.dashjs.org/latest/dash.all.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/shaka-player/4.3.7/shaka-player.compiled.js"></script>
    <script>
        // Pre-load data from PHP
        const preloadedData = <?php echo json_encode($all_content, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

        if ('scrollRestoration' in history) {
            history.scrollRestoration = 'manual';
        }

        // --- IndexedDB Caching ---
        const DB_NAME = 'CineCrazeDB';
        const DB_VERSION = 2;
        const STORE_NAME = 'playlistStore';
        const WATCH_LATER_STORE_NAME = 'watchLaterStore';
        const PLAYLIST_KEY = 'mainPlaylist';

        const dbUtil = {
            open: function() {
                return new Promise((resolve, reject) => {
                    const request = indexedDB.open(DB_NAME, DB_VERSION);
                    request.onupgradeneeded = event => {
                        const db = event.target.result;
                        if (!db.objectStoreNames.contains(STORE_NAME)) {
                            db.createObjectStore(STORE_NAME, { keyPath: 'id' });
                        }
                        if (!db.objectStoreNames.contains(WATCH_LATER_STORE_NAME)) {
                            db.createObjectStore(WATCH_LATER_STORE_NAME, { keyPath: 'id' });
                        }
                    };
                    request.onsuccess = event => {
                        resolve(event.target.result);
                    };
                    request.onerror = event => {
                        console.error('IndexedDB error:', event.target.errorCode);
                        reject('IndexedDB error: ' + event.target.errorCode);
                    };
                });
            },
            get: function(db, key) {
                return new Promise((resolve, reject) => {
                    if (!db) {
                        reject("Database connection is not available.");
                        return;
                    }
                    const transaction = db.transaction([STORE_NAME], 'readonly');
                    const store = transaction.objectStore(STORE_NAME);
                    const request = store.get(key);
                    request.onsuccess = event => {
                        resolve(event.target.result ? event.target.result.data : null);
                    };
                    request.onerror = event => {
                        console.error('Error getting data from DB:', event.target.errorCode);
                        reject('Error getting data from DB: ' + event.target.errorCode);
                    };
                });
            },
            set: function(db, key, value) {
                return new Promise((resolve, reject) => {
                    if (!db) {
                        reject("Database connection is not available.");
                        return;
                    }
                    const transaction = db.transaction([STORE_NAME], 'readwrite');
                    const store = transaction.objectStore(STORE_NAME);
                    const request = store.put({ id: key, data: value, timestamp: new Date() });
                    request.onsuccess = event => {
                        resolve(event.target.result);
                    };
                    request.onerror = event => {
                        console.error('Error setting data in DB:', event.target.errorCode);
                        reject('Error setting data in DB: ' + event.target.errorCode);
                    };
                });
            },
            clear: function(db) {
                return new Promise((resolve, reject) => {
                    if (!db) {
                        reject("Database connection is not available.");
                        return;
                    }
                    const transaction = db.transaction([STORE_NAME], 'readwrite');
                    const store = transaction.objectStore(STORE_NAME);
                    const request = store.clear();
                    request.onsuccess = () => {
                        console.log("IndexedDB store cleared.");
                        resolve();
                    };
                    request.onerror = event => {
                        console.error('Error clearing DB store:', event.target.errorCode);
                        reject('Error clearing DB store: ' + event.target.errorCode);
                    };
                });
            }
        };

        // Configuration
        const watchLaterDbUtil = {
            open: function() {
                return new Promise((resolve, reject) => {
                    const request = indexedDB.open(DB_NAME, DB_VERSION);
                    request.onupgradeneeded = event => {
                        const db = event.target.result;
                        if (!db.objectStoreNames.contains(STORE_NAME)) {
                            db.createObjectStore(STORE_NAME, { keyPath: 'id' });
                        }
                        if (!db.objectStoreNames.contains(WATCH_LATER_STORE_NAME)) {
                            db.createObjectStore(WATCH_LATER_STORE_NAME, { keyPath: 'id' });
                        }
                    };
                    request.onsuccess = event => {
                        resolve(event.target.result);
                    };
                    request.onerror = event => {
                        console.error('IndexedDB error:', event.target.errorCode);
                        reject('IndexedDB error: ' + event.target.errorCode);
                    };
                });
            },
            get: function(db, key) {
                return new Promise((resolve, reject) => {
                    if (!db) {
                        reject("Database connection is not available.");
                        return;
                    }
                    const transaction = db.transaction([WATCH_LATER_STORE_NAME], 'readonly');
                    const store = transaction.objectStore(WATCH_LATER_STORE_NAME);
                    const request = store.get(key);
                    request.onsuccess = event => {
                        resolve(event.target.result);
                    };
                    request.onerror = event => {
                        console.error('Error getting data from DB:', event.target.errorCode);
                        reject('Error getting data from DB: ' + event.target.errorCode);
                    };
                });
            },
            set: function(db, value) {
                return new Promise((resolve, reject) => {
                    if (!db) {
                        return reject("Database connection is not available.");
                    }
                    try {
                        const transaction = db.transaction([WATCH_LATER_STORE_NAME], 'readwrite');
                        transaction.oncomplete = () => {
                            resolve();
                        };
                        transaction.onerror = () => {
                            reject(transaction.error);
                        };
                        const store = transaction.objectStore(WATCH_LATER_STORE_NAME);
                        store.put(value);
                    } catch (error) {
                        reject(error);
                    }
                });
            },
            delete: function(db, key) {
                return new Promise((resolve, reject) => {
                    if (!db) {
                        return reject("Database connection is not available.");
                    }
                    try {
                        const transaction = db.transaction([WATCH_LATER_STORE_NAME], 'readwrite');
                        transaction.oncomplete = () => {
                            resolve();
                        };
                        transaction.onerror = () => {
                            reject(transaction.error);
                        };
                        const store = transaction.objectStore(WATCH_LATER_STORE_NAME);
                        store.delete(key);
                    } catch (error) {
                        reject(error);
                    }
                });
            },
            getAll: function(db) {
                return new Promise((resolve, reject) => {
                    if (!db) {
                        reject("Database connection is not available.");
                        return;
                    }
                    const transaction = db.transaction([WATCH_LATER_STORE_NAME], 'readonly');
                    const store = transaction.objectStore(WATCH_LATER_STORE_NAME);
                    const request = store.getAll();
                    request.onsuccess = event => {
                        resolve(event.target.result);
                    };
                    request.onerror = event => {
                        console.error('Error getting all data from DB:', event.target.errorCode);
                        reject('Error getting all data from DB: ' + event.target.errorCode);
                    };
                });
            }
        };

        const ITEMS_PER_PAGE = 20;
        const LAZY_LOAD_THRESHOLD = 100; // px from bottom to trigger load
        const PLACEHOLDER_IMAGE_URL = 'https://movie-fcs.fwh.is/cinecraze/cinecraze.png';
        // TMDB API removed - using local data only
        
        // SAMPLE DATA - Replace this with your actual data source
        let cineData = preloadedData || null; // Use pre-loaded data
        let cachedContent = [];
        let currentPage = 1;
        let totalPages = 0;
        let isFetching = false;
        let isInitialLoad = true; // Flag for initial content shuffle
        
        // DOM Elements
        const elements = {
            header: document.querySelector('header'),
            searchInput: document.getElementById('search-input'),
            searchResults: document.getElementById('search-results'),
            closeSearchBtn: document.querySelector('.close-search-btn'),
            themeToggle: document.getElementById('theme-toggle'),
            viewToggleBtn: document.getElementById('view-toggle-btn'),
            contentGrid: document.getElementById('content-grid'),
            contentList: document.getElementById('content-list'),
            watchLaterGrid: document.getElementById('watch-later-grid'),
            genreFilter: document.getElementById('genre-filter'),
            countryFilter: document.getElementById('country-filter'),
            yearFilter: document.getElementById('year-filter'),
            sortFilter: document.getElementById('sort-filter'),
            viewerPage: document.getElementById('viewer-page'),
            viewerTitle: document.getElementById('viewer-title'),
            viewerDescription: document.getElementById('viewer-description'),
            // viewerRating: document.getElementById('viewer-rating'), // Not used
            // viewerDuration: document.getElementById('viewer-duration'), // Not used
            // viewerYear: document.getElementById('viewer-year'), // Not used
            // viewerCountry: document.getElementById('viewer-country'), // Not used
            serverSelect: document.getElementById('quality-select'),
            playerServerSelector: document.getElementById('player-server-selector'),
            serverSelectorContainer: document.getElementById('server-selector-container'),
            serverSelector: document.getElementById('server-selector'),
            episodeSelectorContainer: document.getElementById('episode-selector-container'),
            episodeSelector: document.getElementById('episode-selector'),
            seasonSelector: document.getElementById('season-selector'),
            seasonsGrid: document.getElementById('seasons-grid'),
            relatedGrid: document.getElementById('related-grid'),
            player: document.getElementById('player'),
            playerMessageArea: document.getElementById('player-message-area'),
            carouselInner: document.getElementById('carousel-inner'),
            carouselPrev: document.getElementById('carousel-prev'),
            carouselNext: document.getElementById('carousel-next'),
            carouselIndicators: document.getElementById('carousel-indicators'),
            mobileSearchBtn: document.getElementById('mobile-search-btn'),
            searchContainer: document.querySelector('.search-container'),
            backButton: document.getElementById('back-button'),
            loadingSpinner: document.getElementById('loading-spinner'),
            likeCheckbox: document.getElementById('like-checkbox'),
            dislikeCheckbox: document.getElementById('dislike-checkbox'),
            shareCheckbox: document.getElementById('share-checkbox'),
            likeCountSpan: document.getElementById('like-count-span'),
            dislikeCountSpan: document.getElementById('dislike-count-span'),
            progressBarContainer: document.getElementById('progress-bar-container'),
            progressBar: document.getElementById('progress-bar'),
            progressBarText: document.getElementById('progress-bar-text'),
            // Containers for moving elements
            relatedVideosContainer: document.querySelector('.related-videos'),
            videoDetailsContainer: document.querySelector('.video-details'),
            // Episode navigation buttons (to be created)
            prevEpisodeBtn: null,
            nextEpisodeBtn: null,
            hamburgerBtn: document.getElementById('hamburger-btn'),
            mobileFiltersMenu: document.querySelector('.mobile-filters-menu'),
            stretchBtn: document.getElementById('stretch-btn'),

            // Parental Controls
            parentalControlsLink: document.getElementById('parental-controls-link'),
            parentalControlsModal: document.getElementById('parental-controls-modal'),
            closeParentalControlsModal: document.getElementById('close-parental-controls-modal'),
            pinDisplay: document.querySelector('.pin-display'),
            pinStatusText: document.querySelector('.pin-status-text'),
            pinPad: document.querySelector('.pin-pad'),
            resetPinBtn: document.getElementById('reset-pin-btn'),
            changeRatingsBtn: document.getElementById('change-ratings-btn'),
            allowedRatingsDisplay: document.getElementById('allowed-ratings-display'),
            unratedContentToggle: document.getElementById('unrated-content-toggle'),

            ratingsSelectModal: document.getElementById('ratings-select-modal'),
            ratingsCheckboxContainer: document.getElementById('ratings-checkbox-container'),
            cancelRatingsBtn: document.getElementById('cancel-ratings-btn'),
            okRatingsBtn: document.getElementById('ok-ratings-btn'),

            pinEntryModal: document.getElementById('pin-entry-modal'),
            closePinEntryModal: document.getElementById('close-pin-entry-modal'),
            pinEntryTitle: document.getElementById('pin-entry-title'),
            pinDisplayInput: document.querySelector('.pin-display-input'),
            pinStatusTextInput: document.querySelector('.pin-status-text-input'),
            pinPadInput: document.querySelector('.pin-pad-input'),
            cancelPinEntryBtn: document.getElementById('cancel-pin-entry-btn'),
            okPinEntryBtn: document.getElementById('ok-pin-entry-btn')
        };
        
        // State
        let currentView = 'grid';
        let currentContentInfo = {}; // To store current viewed content info for save/like
        let currentContent = [];
        let currentCarouselIndex = 0;
        let playerInstance = null;
        let dashPlayer = null;
        let currentEpisode = null;
        let currentSeason = null;
        let currentSeries = null;
        let isStretched = false;
        let watchLaterItemsSet = new Set();

        // Parental Controls State
        let parentalControls = {
            pin: null,
            allowedRatings: [],
            allowUnrated: true
        };
        let currentPinInput = "";
        let pinEntryCallback = null; // To store what to do after successful PIN entry
        let isSettingPin = false;
        let tempPin = '';
        
        // Function to shuffle an array
        function shuffleArray(array) {
            for (let i = array.length - 1; i > 0; i--) {
                const j = Math.floor(Math.random() * (i + 1));
                [array[i], array[j]] = [array[j], array[i]];
            }
            return array;
        }
        
        // Sort content based on criteria
        function sortContent(content, criteria) {
            return [...content].sort((a, b) => {
                switch(criteria) {
                    case 'newest':
                        return (parseInt(b.Year) || 0) - (parseInt(a.Year) || 0);
                    case 'popular':
                        return (parseFloat(b.Rating) || 0) - (parseFloat(a.Rating) || 0);
                    case 'rating':
                        return (parseFloat(b.Rating) || 0) - (parseFloat(a.Rating) || 0);
                    default:
                        return 0;
                }
            });
        }
        
        // Function to create episode navigation buttons
        function createEpisodeNavigationButtons() {
            elements.prevEpisodeBtn = document.createElement('button');
            elements.prevEpisodeBtn.type = 'button';
            elements.prevEpisodeBtn.className = 'plyr__controls__item plyr__control'; // Standard Plyr classes
            elements.prevEpisodeBtn.id = 'prev-episode-btn';
            elements.prevEpisodeBtn.setAttribute('aria-label', 'Previous Episode');
            elements.prevEpisodeBtn.innerHTML = '<i class="fas fa-backward"></i>';
            elements.prevEpisodeBtn.style.display = 'none'; // Initially hidden

            elements.nextEpisodeBtn = document.createElement('button');
            elements.nextEpisodeBtn.type = 'button';
            elements.nextEpisodeBtn.className = 'plyr__controls__item plyr__control'; // Standard Plyr classes
            elements.nextEpisodeBtn.id = 'next-episode-btn';
            elements.nextEpisodeBtn.setAttribute('aria-label', 'Next Episode');
            elements.nextEpisodeBtn.innerHTML = '<i class="fas fa-forward"></i>';
            elements.nextEpisodeBtn.style.display = 'none'; // Initially hidden
        }

        // Function to generate star rating HTML
        function generateStarRating(ratingStr) {
            const rating = parseFloat(ratingStr);
            if (isNaN(rating) || rating < 0 || rating > 10) {
                return '<span>N/A</span>';
            }

            const numStars = 5;
            let starsHtml = '<span class="star-rating">';
            // Convert 0-10 scale to 0-5 scale for star calculation
            const ratingOutOfFive = rating / 2; 

            for (let i = 0; i < numStars; i++) {
                if (ratingOutOfFive >= i + 1) {
                    starsHtml += '<i class="fas fa-star"></i>'; // Full star
                } else if (ratingOutOfFive >= i + 0.5) {
                    starsHtml += '<i class="fas fa-star-half-alt"></i>'; // Half star
                } else {
                    starsHtml += '<i class="far fa-star"></i>'; // Empty star
                }
            }
            starsHtml += '</span>';
            return starsHtml;
        }

        const countryNameToCodeMap = {
            "USA": "us", "United States": "us", "United States of America": "us",
            "UK": "gb", "United Kingdom": "gb",
            "Canada": "ca",
            "Japan": "jp",
            "South Korea": "kr", "Korea": "kr",
            "France": "fr",
            "Germany": "de",
            "India": "in",
            "China": "cn",
            "Spain": "es",
            "Italy": "it",
            "Australia": "au",
            "Brazil": "br",
            "Mexico": "mx",
            "Philippines": "ph",
            // Add more common mappings as needed
        };

        function getCountryFlagHtml(countryName) {
            if (!countryName) return '';
            const countryCode = countryNameToCodeMap[countryName.trim()] || countryName.toLowerCase().replace(/\s+/g, '-'); // Fallback for unmapped
            if (countryCode) {
                return `<span class="fi fi-${countryCode.toLowerCase()}"></span>`;
            }
            return ''; // Return empty if no code found, or could return countryName text
        }

        // Initialize the app
        async function init() {
            // Cache watch later items
            try {
                const db = await watchLaterDbUtil.open();
                const watchLaterItems = await watchLaterDbUtil.getAll(db);
                db.close();
                watchLaterItemsSet = new Set(watchLaterItems.map(item => item.id));
            } catch (error) {
                console.error("Failed to cache watch later items:", error);
            }

            setupParentalControls(); // Setup parental controls first
            createEpisodeNavigationButtons(); // Create buttons before they might be needed
            fetchData().then(() => {
                renderCarousel();
                renderContentFilters();
                renderContent();
                setupEventListeners();
                setupMobileBackButton(); // Setup mobile back button support
                updateCarousel();
                setupLazyLoading();
                history.replaceState({ page: 'browse' }, 'Browse Content', window.location.pathname + window.location.search);
            });
        }
        
        // Helpers for segmented playlist fetching
        const MAX_PLAYLIST_SEGMENTS = 50; // safety cap
        function getBasePathFromUrl(url) {
            if (!url) return '';
            const lastSlashIndex = url.lastIndexOf('/');
            return lastSlashIndex >= 0 ? url.substring(0, lastSlashIndex + 1) : url;
        }
        function replaceFileName(url, fileName) {
            return getBasePathFromUrl(url) + fileName;
        }
        function withCacheBuster(url) {
            const separator = url.includes('?') ? '&' : '?';
            return url + separator + 't=' + new Date().getTime();
        }
        async function tryFetchJson(url) {
            try {
                const response = await fetch(withCacheBuster(url));
                if (!response.ok) return null;
                return await response.json();
            } catch (err) {
                console.warn('tryFetchJson failed for', url, err);
                return null;
            }
        }
        function mergeCineDataSegments(segments) {
            if (!Array.isArray(segments) || segments.length === 0) return null;
            const categoryNameToCategory = new Map();
            const categoryNameToSeenTitles = new Map();
            for (const segment of segments) {
                if (!segment || !Array.isArray(segment.Categories)) continue;
                for (const category of segment.Categories) {
                    if (!category || !category.MainCategory || !Array.isArray(category.Entries)) continue;
                    const key = String(category.MainCategory);
                    if (!categoryNameToCategory.has(key)) {
                        categoryNameToCategory.set(key, { MainCategory: category.MainCategory, Entries: [] });
                        categoryNameToSeenTitles.set(key, new Set());
                    }
                    const targetCategory = categoryNameToCategory.get(key);
                    const seen = categoryNameToSeenTitles.get(key);
                    for (const entry of category.Entries) {
                        const title = entry && entry.Title ? String(entry.Title) : null;
                        if (!title) continue;
                        if (seen.has(title)) continue;
                        seen.add(title);
                        targetCategory.Entries.push(entry);
                    }
                }
            }
            return { Categories: Array.from(categoryNameToCategory.values()) };
        }
        async function tryFetchSegmented(basePlaylistUrl) {
            const segments = [];
            let foundAny = false;
            for (let i = 1; i <= MAX_PLAYLIST_SEGMENTS; i++) {
                const progress = Math.round((i / MAX_PLAYLIST_SEGMENTS) * 100);
                elements.progressBar.style.width = `${progress}%`;
                elements.progressBarText.textContent = `Fetching segment ${i} of ${MAX_PLAYLIST_SEGMENTS}...`;

                const numberedUrl = replaceFileName(basePlaylistUrl, `playlist${i}.json`);
                let data = await tryFetchJson(numberedUrl);
                if (!data) {
                    const dashedUrl = replaceFileName(basePlaylistUrl, `playlist-${i}.json`);
                    data = await tryFetchJson(dashedUrl);
                }
                if (!data) {
                    if (foundAny) {
                        elements.progressBar.style.width = `100%`;
                        elements.progressBarText.textContent = `Found ${segments.length} segments. Merging data...`;
                    }
                    break;
                }
                foundAny = true;
                segments.push(data);
            }
            if (!foundAny) return null;
            return mergeCineDataSegments(segments);
        }
        
        // Data is now pre-loaded, so this function is much simpler.
        // It ensures the rest of the app that depends on this Promise still works.
        async function fetchData() {
            return new Promise((resolve, reject) => {
                if (cineData && cineData.Categories) {
                    console.log("✅ Data was pre-loaded successfully via PHP.");
                    // Hide loading indicators that might have been shown by default
                    elements.progressBarContainer.style.display = 'none';
                    elements.loadingSpinner.style.display = 'none';
                    resolve();
                } else {
                    console.error("❌ Pre-loaded data is not available. Check PHP block.");
                    const errorMessage = document.createElement('div');
                    errorMessage.style.cssText = `
                        position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%);
                        background: var(--youtube-gray); padding: 20px; border-radius: 8px; text-align: center;
                        z-index: 10000; max-width: 400px;
                    `;
                    errorMessage.innerHTML = `
                        <h3>⚠️ Data Loading Failed</h3>
                        <p>Could not load initial content from the server. Please ensure the database is set up correctly.</p>
                        <a href="setup.php" style="
                            display: inline-block; background: var(--primary); color: white; border: none; padding: 10px 20px;
                            border-radius: 4px; cursor: pointer; margin-top: 10px; text-decoration: none;
                        ">Run Setup</a>
                    `;
                    document.body.appendChild(errorMessage);
                    reject("Pre-loaded data not found.");
                }
            });
        }
        
        // TMDB API function removed - using local data only

        // No longer needed, data is pre-enriched
        // async function fixDataInconsistencies() { ... }

        // Helper function to get random distinct items from an array
        function getRandomItems(arr, count) {
            if (!arr || arr.length === 0) return [];
            const shuffled = [...arr].sort(() => 0.5 - Math.random());
            return shuffled.slice(0, Math.min(count, arr.length));
        }
        
        // Render carousel
        function renderCarousel() {
            if (!cineData || !cineData.Categories || cineData.Categories.length < 3) {
                console.warn("Carousel data is not as expected. Skipping render.");
                return;
            }

            const featuredContent = [];
            const moviesCategory = cineData.Categories.find(cat => cat.MainCategory.toLowerCase().includes('movie'));
            const seriesCategory = cineData.Categories.find(cat => cat.MainCategory.toLowerCase().includes('series'));
            const liveCategory = cineData.Categories.find(cat => cat.MainCategory.toLowerCase().includes('live'));

            // Add randomly selected movies
            if (moviesCategory && moviesCategory.Entries) {
                getRandomItems(moviesCategory.Entries, 3).forEach(movie => {
                    featuredContent.push({
                        type: 'movie',
                        title: movie.Title,
                        description: movie.Description,
                        image: movie.Poster,
                        // Store original item for click handling
                        originalItem: movie 
                    });
                });
            }

            // Add randomly selected TV series
            if (seriesCategory && seriesCategory.Entries) {
                getRandomItems(seriesCategory.Entries, 1).forEach(series => {
                    featuredContent.push({
                        type: 'series',
                        title: series.Title,
                        description: series.Description || `Popular ${series.SubCategory} series`,
                        image: series.Poster,
                        originalItem: series 
                    });
                });
            }

            // Add randomly selected live TV
            if (liveCategory && liveCategory.Entries) {
                getRandomItems(liveCategory.Entries, 1).forEach(live => {
                    featuredContent.push({
                        type: 'live',
                        title: live.Title,
                        description: live.Description,
                        image: live.Poster,
                        originalItem: live 
                    });
                });
            }

            // Shuffle the order of the collected featured content
            shuffleArray(featuredContent);

            // Render carousel items
            elements.carouselInner.innerHTML = '';
            elements.carouselIndicators.innerHTML = '';

            featuredContent.forEach((item, index) => {
                // Carousel item
                const carouselItem = document.createElement('div');
                carouselItem.className = 'carousel-item';
                // Get year from the original item if available
                const year = item.originalItem && item.originalItem.Year ? item.originalItem.Year : '';
                const ratingHtml = item.originalItem ? generateStarRating(item.originalItem.Rating) : '';

                carouselItem.innerHTML = `
                    <img src="${item.image}" alt="${item.title}" onerror="this.onerror=null; this.src='${PLACEHOLDER_IMAGE_URL}';">
                    <div class="carousel-content">
                        <h2>${item.title}</h2>
                        <div class="carousel-meta">
                            ${ratingHtml}
                            ${year ? `<span class="carousel-year">${year}</span>` : ''}
                        </div>
                        <p>${item.description}</p>
                    </div>
                `;
                elements.carouselInner.appendChild(carouselItem);

                // Indicator
                const indicator = document.createElement('div');
                indicator.className = 'indicator';
                indicator.dataset.index = index;
                if (index === 0) indicator.classList.add('active');
                elements.carouselIndicators.appendChild(indicator);

                // Add click event to indicator
                indicator.addEventListener('click', () => {
                    currentCarouselIndex = index;
                    updateCarousel();
                });

                // Make carousel item clickable
                carouselItem.addEventListener('click', () => {
                    if (item.originalItem) {
                        // The 'type' is already correctly set in featuredContent items
                        openViewer({ ...item.originalItem, type: item.type });
                    } else {
                        // Fallback or error if originalItem is somehow missing
                        console.error("Carousel item is missing originalItem data:", item);
                    }
                });
            });
        }
        
        // Render content filters
        function renderContentFilters() {
            if (!cineData || !cineData.Categories) return;

            // Get unique genres and countries
            const genres = new Set();
            const countries = new Set();

            cineData.Categories.forEach(category => {
                category.Entries.forEach(entry => {
                    if (entry.SubCategory) genres.add(entry.SubCategory);
                    if (entry.Country) countries.add(entry.Country);
                });
            });

            // Populate genre filter
            const genreFilter = elements.genreFilter;
            genres.forEach(genre => {
                const option = document.createElement('option');
                option.value = genre.toLowerCase();
                option.textContent = genre;
                genreFilter.appendChild(option);
            });

            // Populate country filter
            const countryFilter = elements.countryFilter;
            countries.forEach(country => {
                const option = document.createElement('option');
                option.value = country.toLowerCase();
                option.textContent = country;
                countryFilter.appendChild(option);
            });

            // Populate year filter
            const yearFilter = elements.yearFilter;
            const years = new Set();
            cineData.Categories.forEach(category => {
                category.Entries.forEach(entry => {
                    if (entry.Year) {
                        years.add(entry.Year);
                    }
                });
            });

            const sortedYears = Array.from(years).sort((a, b) => b - a);
            sortedYears.forEach(year => {
                const option = document.createElement('option');
                option.value = year;
                option.textContent = year;
                yearFilter.appendChild(option);
            });
        }
        
        // Render content based on filters
        async function renderContent(category = 'all') {
            if (!cineData || !cineData.Categories) return;

            const filtersSection = document.querySelector('.filters-section');

            // Reset pagination
            currentPage = 1;
            currentContent = [];

            if (category === 'watch-later') {
                filtersSection.style.display = 'none'; // Hide filters for Watch Later
                const db = await watchLaterDbUtil.open();
                const watchLaterItems = await watchLaterDbUtil.getAll(db);
                db.close();
                currentContent = watchLaterItems.map(item => ({ ...item, type: item.type || 'movie' }));

                totalPages = Math.ceil(currentContent.length / ITEMS_PER_PAGE);
                cachedContent = [...currentContent];
                currentView = 'watch-later'; // Set the view
                renderCurrentView();
                renderPaginationControls();
                setupLazyLoading();
                return;
            } else {
                const icon = elements.viewToggleBtn.querySelector('i');
                if (icon.classList.contains('fa-list')) {
                    currentView = 'list';
                } else {
                    currentView = 'grid';
                }
                filtersSection.style.display = 'block'; // Show filters for other categories
            }

            // Get selected category
            const genre = elements.genreFilter.value.toLowerCase();
            const country = elements.countryFilter.value.toLowerCase();
            const year = elements.yearFilter.value;
            const sortBy = elements.sortFilter.value;

            // Filter content
            cineData.Categories.forEach(cat => {
                if (category === 'all' || cat.MainCategory.toLowerCase().includes(category)) {
                    cat.Entries.forEach(entry => {
                        // Check genre and country filters
                        const genreMatch = genre === 'all' ||
                            (entry.SubCategory && entry.SubCategory.toLowerCase().includes(genre));
                        const countryMatch = country === 'all' ||
                            (entry.Country && entry.Country.toLowerCase().includes(country));
                        const yearMatch = year === 'all' || (entry.Year && entry.Year.toString() === year);

                        if (genreMatch && countryMatch && yearMatch && isContentAllowed(entry)) {
                            currentContent.push({
                                ...entry,
                                type: cat.MainCategory.toLowerCase().includes('movie') ? 'movie' :
                                    cat.MainCategory.toLowerCase().includes('series') ? 'series' : 'live'
                            });
                        }
                    });
                }
            });

            // Shuffle content on initial load
            if (isInitialLoad) {
                currentContent = shuffleArray(currentContent);
                isInitialLoad = false;
            }
            // Apply sorting if not initial load
            else {
                currentContent = sortContent(currentContent, sortBy);
            }

            // Calculate total pages
            totalPages = Math.ceil(currentContent.length / ITEMS_PER_PAGE);

            // Cache filtered content
            cachedContent = [...currentContent];

            // Render content
            renderCurrentView();
            renderPaginationControls();

            // Re-initialize lazy loading for the new content
            setupLazyLoading();
        }

        // Render the current view (grid or list)
        function renderCurrentView() {
            elements.contentGrid.style.display = 'none';
            elements.contentList.style.display = 'none';
            elements.watchLaterGrid.style.display = 'none';

            let container;
            let viewClass = 'grid';

            if (currentView === 'watch-later') {
                container = elements.watchLaterGrid;
                container.style.display = 'grid';
            } else if (currentView === 'grid') {
                container = elements.contentGrid;
                container.style.display = 'grid';
            } else { // list
                container = elements.contentList;
                container.style.display = 'flex';
                viewClass = 'list';
            }

            container.innerHTML = '';

            const startIndex = (currentPage - 1) * ITEMS_PER_PAGE;
            const endIndex = startIndex + ITEMS_PER_PAGE;
            const itemsToRender = currentContent.slice(startIndex, endIndex);

            itemsToRender.forEach(item => {
                const card = createContentCard(item, viewClass);
                container.appendChild(card);
            });

            setupLazyLoading();
        }
        
        // Create a content card
        function createContentCard(item, viewType) {
            const card = document.createElement('div');
            card.className = `content-card ${viewType}`;
            card.dataset.id = item.Title.replace(/\s+/g, '-').toLowerCase();
            card.dataset.type = item.type;

            // Create badge based on content type
            let badge = '';
            if (item.type === 'movie') {
                badge = '<div class="card-badge badge-movie">MOVIE</div>';
            } else if (item.type === 'series') {
                badge = '<div class="card-badge badge-series">SERIES</div>';
            } else if (item.type === 'live') {
                badge = '<div class="card-badge badge-live">LIVE</div>';
            }

            let deleteBtn = '';
            let watchLaterIcon = '';
            if (currentView === 'watch-later') {
                deleteBtn = `<button class="delete-watch-later-btn" data-id="${item.id}"><i class="fas fa-trash"></i></button>`;
            } else {
                const isActive = watchLaterItemsSet.has(item.Title);
                watchLaterIcon = `<button class="card-watch-later-btn ${isActive ? 'active' : ''}" data-id="${item.Title.replace(/\s+/g, '-').toLowerCase()}"><i class="fas fa-clock"></i></button>`;
            }

            card.innerHTML = `
                <div class="card-img">
                    <img data-src="${item.Thumbnail || item.Poster}" alt="${item.Title}" class="lazy-image" loading="lazy" onerror="this.onerror=null; this.src='${PLACEHOLDER_IMAGE_URL}';">
                    ${badge}
                    ${deleteBtn}
                    ${viewType === 'grid' ? watchLaterIcon : ''}
                </div>
                <div class="card-info">
                    <div class="card-title-container">
                        <h3 class="card-title">${item.Title}</h3>
                        ${viewType === 'list' ? watchLaterIcon : ''}
                    </div>
                    <div class="card-meta">
                        ${generateStarRating(item.Rating)}
                        ${viewType === 'list' ? 
                            `<span class="meta-netflix-style">
                                ${item.Year ? `<span>${item.Year}</span>` : ''}
                                ${item.Country ? `<span class="meta-country">${getCountryFlagHtml(item.Country)} ${item.Country}</span>` : ''}
                                ${item.Duration ? `<span>${item.Duration}</span>` : ''}
                            </span>` :
                            `${item.Duration ? `<span><i class="fas fa-clock"></i> ${item.Duration}</span>` : ''}
                             ${item.Country ? `<span class="meta-country">${getCountryFlagHtml(item.Country)} ${item.Country}</span>` : ''}`
                        }
                    </div>
                    ${viewType === 'list' ? `<p class="card-description">${item.Description || ''}</p>` : ''}
                </div>
            `;

            if (currentView === 'watch-later') {
                const btn = card.querySelector('.delete-watch-later-btn');
                btn.addEventListener('click', (e) => {
                    e.stopPropagation(); // prevent card click
                    deleteFromWatchLater(item.id);
                });
            } else {
                const watchLaterBtn = card.querySelector('.card-watch-later-btn');
                if (watchLaterBtn) {
                    watchLaterBtn.addEventListener('click', (e) => {
                        e.stopPropagation(); // prevent card click
                        toggleWatchLater(item, e.currentTarget);
                    });
                }
            }

            card.addEventListener('click', () => openViewer(item));
            return card;
        }


        // Render pagination controls
        function renderPaginationControls() {
            const paginationContainer = document.getElementById('pagination-container');
            paginationContainer.innerHTML = '';
            if (totalPages <= 1) return;

            const createPageButton = (page, text = page) => {
                const button = document.createElement('button');
                button.classList.add('pagination-btn');
                button.textContent = text;
                if (page === currentPage) {
                    button.classList.add('active');
                }
                button.addEventListener('click', () => {
                    currentPage = page;
                    renderCurrentView();
                    renderPaginationControls();
                    if (window.innerWidth <= 576) {
                        document.getElementById('content-grid').scrollIntoView({ behavior: 'smooth' });
                    }
                });
                return button;
            };

            const createEllipsis = () => {
                const ellipsis = document.createElement('span');
                ellipsis.textContent = '...';
                ellipsis.classList.add('pagination-ellipsis');
                return ellipsis;
            };

            // Previous Button
            const prevButton = document.createElement('button');
            prevButton.classList.add('pagination-btn');
            prevButton.innerHTML = '<i class="fas fa-chevron-left"></i>';
            prevButton.disabled = currentPage === 1;
            prevButton.addEventListener('click', () => {
                if (currentPage > 1) {
                    currentPage--;
                    renderCurrentView();
                    renderPaginationControls();
                    if (window.innerWidth <= 576) {
                        document.getElementById('content-grid').scrollIntoView({ behavior: 'smooth' });
                    }
                }
            });
            paginationContainer.appendChild(prevButton);

            if (totalPages <= 7) {
                for (let i = 1; i <= totalPages; i++) {
                    paginationContainer.appendChild(createPageButton(i));
                }
            } else {
                if (currentPage < 5) {
                    for (let i = 1; i <= 5; i++) {
                        paginationContainer.appendChild(createPageButton(i));
                    }
                    paginationContainer.appendChild(createEllipsis());
                    paginationContainer.appendChild(createPageButton(totalPages));
                } else if (currentPage > totalPages - 4) {
                    paginationContainer.appendChild(createPageButton(1));
                    paginationContainer.appendChild(createEllipsis());
                    for (let i = totalPages - 4; i <= totalPages; i++) {
                        paginationContainer.appendChild(createPageButton(i));
                    }
                } else {
                    paginationContainer.appendChild(createPageButton(1));
                    paginationContainer.appendChild(createEllipsis());
                    for (let i = currentPage - 1; i <= currentPage + 1; i++) {
                        paginationContainer.appendChild(createPageButton(i));
                    }
                    paginationContainer.appendChild(createEllipsis());
                    paginationContainer.appendChild(createPageButton(totalPages));
                }
            }

            // Next Button
            const nextButton = document.createElement('button');
            nextButton.classList.add('pagination-btn');
            nextButton.innerHTML = '<i class="fas fa-chevron-right"></i>';
            nextButton.disabled = currentPage === totalPages;
            nextButton.addEventListener('click', () => {
                if (currentPage < totalPages) {
                    currentPage++;
                    renderCurrentView();
                    renderPaginationControls();
                    if (window.innerWidth <= 576) {
                        document.getElementById('content-grid').scrollIntoView({ behavior: 'smooth' });
                    }
                }
            });
            paginationContainer.appendChild(nextButton);
        }

        // Setup lazy loading for images
        function setupLazyLoading() {
            const lazyImages = document.querySelectorAll('.lazy-image');

            const lazyLoad = () => {
                lazyImages.forEach(img => {
                    if (img.classList.contains('loaded')) return;

                    const rect = img.getBoundingClientRect();
                    if (rect.top < window.innerHeight + LAZY_LOAD_THRESHOLD) {
                        img.src = img.dataset.src;
                        img.onload = () => {
                            img.classList.add('loaded');
                        };
                    }
                });
            };

            // Initial load
            lazyLoad();

            // Listen to scroll and resize events
            window.addEventListener('scroll', lazyLoad);
            window.addEventListener('resize', lazyLoad);
        }
        
        // Open viewer for content
        function openViewer(content) {
            // Parental Control Check
            if (!isContentAllowed(content)) {
                elements.pinEntryTitle.textContent = "Enter PIN to Watch";
                showModal(elements.pinEntryModal);
                pinEntryCallback = () => {
                    // Temporarily bypass the check for this one time
                    openViewerInternal(content);
                };
                return; // Stop execution until PIN is entered
            }
            openViewerInternal(content);
        }

        function openViewerInternal(content) {
            if (playerInstance) {
                playerInstance.destroy();
                playerInstance = null;
            }
            if (dashPlayer) {
                dashPlayer.reset();
                dashPlayer = null;
            }

            // Re-create the video element to ensure a clean state
            const playerContainer = document.querySelector('.player-container');
            playerContainer.innerHTML = '<video id="player" playsinline controls></video>';
            elements.player = document.getElementById('player');


            currentContentInfo = content;

            incrementViewCount(content.Title);
            history.pushState({ page: 'viewer', contentId: content.Title }, 'View Content', '#viewer');

            document.querySelector('.carousel').style.display = 'none';
            document.querySelector('.filters-section').style.display = 'none';
            document.querySelector('.content-container').style.display = 'none';

            const setupViewerAndPlay = () => {
                elements.viewerTitle.textContent = content.Title;
                elements.viewerDescription.textContent = content.Description || 'No description available.';
                // Reset selectors
                if (elements.serverSelectorContainer) {
                    elements.serverSelectorContainer.style.display = 'none';
                }
                if (elements.serverSelector) {
                    elements.serverSelector.innerHTML = '';
                }
                elements.episodeSelectorContainer.style.display = 'none';
                elements.episodeSelector.innerHTML = '<option value="">Select Episode</option>';
                elements.seasonSelector.style.display = 'none';
                if (elements.episodeSelectorContainer.parentNode !== elements.videoDetailsContainer) {
                    elements.videoDetailsContainer.appendChild(elements.episodeSelectorContainer);
                }
                if (elements.seasonSelector.parentNode !== elements.videoDetailsContainer) {
                    elements.videoDetailsContainer.appendChild(elements.seasonSelector);
                }

                currentSeason = null;
                currentEpisode = null;
                currentSeries = content;

                if (content.type !== 'series' && content.Servers) {
                    updatePlayerSource(content.Servers);
                }
                elements.seasonsGrid.innerHTML = '';

                // ensureEpisodeButtonsInControls(); // This call is removed, logic moved to updateNavigationButtonsState

                if (content.type === 'series' && content.Seasons) {
                    if (elements.relatedVideosContainer) {
                        elements.relatedVideosContainer.prepend(elements.seasonSelector);
                        elements.relatedVideosContainer.prepend(elements.episodeSelectorContainer);
                    }
                    elements.seasonSelector.style.display = 'block';
                    content.Seasons.forEach(season => { /* ... render season card ... */
                        const seasonCard = document.createElement('div');
                        seasonCard.className = 'season-card';
                        seasonCard.innerHTML = `
                            <img src="${season.SeasonPoster || content.Thumbnail}" alt="Season ${season.Season}" onerror="this.onerror=null; this.src='${PLACEHOLDER_IMAGE_URL}';">
                            <div class="season-info">
                                <h4>Season ${season.Season}</h4>
                            </div>
                        `;
                        seasonCard.addEventListener('click', () => {
                            openSeason(season);
                        });
                        elements.seasonsGrid.appendChild(seasonCard);
                    });
                    if (content.Seasons.length > 0) {
                        openSeason(content.Seasons[0]); // This calls playEpisode -> updateNavigationButtonsState
                    } else {
                        updateNavigationButtonsState(); // Hide buttons if no seasons
                    }
                } else { // Movie or Live TV
                    updateNavigationButtonsState(); // Hide episode buttons for non-series
                }

                // Render related content
                elements.relatedGrid.innerHTML = '';
                const relatedContent = cachedContent.filter(item => item.Title !== content.Title)
                                               .sort(() => 0.5 - Math.random()).slice(0, 8);
                relatedContent.forEach(item => { /* ... render related card ... */ 
                    const card = document.createElement('div');
                    card.className = 'related-card';
                    const ratingHtml = generateStarRating(item.Rating);
                    let metaNetflixStyle = '<span class="meta-netflix-style">';
                    if (item.Year) metaNetflixStyle += `<span>${item.Year}</span>`;
                    if (item.Country) metaNetflixStyle += `<span class="meta-country">${getCountryFlagHtml(item.Country)} ${item.Country}</span>`;
                    if (item.Duration) metaNetflixStyle += `<span>${item.Duration}</span>`;
                    metaNetflixStyle += '</span>';

                    card.innerHTML = `
                        <div class="related-thumbnail">
                            <img src="${item.Thumbnail || item.Poster}" alt="${item.Title}" onerror="this.onerror=null; this.src='${PLACEHOLDER_IMAGE_URL}';">
                        </div>
                        <div class="related-info">
                            <h4>${item.Title}</h4>
                            <div class="related-meta-stars">${ratingHtml}</div>
                            <div class="related-meta-details">${metaNetflixStyle}</div>
                            <div class="related-channel">CineCraze</div>
                        </div>
                    `;
                    card.addEventListener('click', () => openViewer(item));
                    elements.relatedGrid.appendChild(card);
                });
            };

            if (!playerInstance) {
                playerInstance = new Plyr('#player', {
                    controls: ['play-large', 'play', 'progress', 'current-time', 'mute', 'volume', 'captions', 'settings', 'pip', 'fullscreen'],
                    youtube: { noCookie: true, rel: 0, showinfo: 0, iv_load_policy: 3 },
                    quality: {
                        default: 720,
                        options: [1080, 720, 480, 360, 240],
                    },
                    autoplay: false, // Start with autoplay disabled for better mobile compatibility
                    muted: false, // Allow sound by default
                });

                playerInstance.once('ready', event => {
                    setupViewerAndPlay();
                    addStretchButtonToPlayer();
                    // Event listeners for fullscreen etc. should be attached here too, only once
                    playerInstance.on('enterfullscreen', () => {
                        if (screen.orientation && screen.orientation.lock) {
                            screen.orientation.lock('landscape').catch(error => {
                                console.log('Orientation lock failed: ', error);
                            });
                        }
                    });
                    playerInstance.on('exitfullscreen', () => {
                        if (screen.orientation && screen.orientation.unlock) {
                            screen.orientation.unlock();
                        }
                        if (elements.searchContainer) {
                            elements.searchContainer.classList.remove('show');
                        }
                    });
                });

                document.addEventListener('fullscreenchange', () => {
                    if (document.fullscreenElement && document.fullscreenElement.classList.contains('external-content-iframe')) {
                        if (screen.orientation && screen.orientation.lock) {
                            screen.orientation.lock('landscape').catch(error => {
                                console.log('Orientation lock failed: ', error);
                            });
                        }
                    }
                });
            } else {
                // Player already exists, it should be ready.
                setupViewerAndPlay();
            }

            elements.viewerPage.style.display = 'block';
            window.scrollTo(0, 0);

            // --- Correct Event Listener Setup for Checkboxes ---
            updateLikeDislikeUI(content.Title);

            // Remove old listeners and add new ones to prevent duplicates.
            // The 'change' event is used for checkboxes.
            const setupListener = (element, handler) => {
                if (!element) return; // Guard against null elements
                if (element._changeHandler) {
                    element.removeEventListener('change', element._changeHandler);
                }
                element._changeHandler = handler;
                element.addEventListener('change', element._changeHandler);
            };

            // The handler functions (handleLike, handleDislike, handleShareVideo)
            // already use the global `currentContentInfo`, so we don't need to pass `content`.
            setupListener(elements.likeCheckbox, handleLike);
            setupListener(elements.dislikeCheckbox, handleDislike);
            setupListener(elements.shareCheckbox, () => {
                handleShareVideo();
                // Uncheck the share box immediately after action for better UX
                setTimeout(() => {
                    if (elements.shareCheckbox) {
                        elements.shareCheckbox.checked = false;
                    }
                }, 500);
            });

            const watchLaterBtn = document.getElementById('watch-later-btn');
            if (watchLaterBtn) {
                const newToggleHandler = () => toggleWatchLater(content);
                if (watchLaterBtn._toggleHandler) {
                    watchLaterBtn.removeEventListener('click', watchLaterBtn._toggleHandler);
                }
                watchLaterBtn.addEventListener('click', newToggleHandler);
                watchLaterBtn._toggleHandler = newToggleHandler;
                updateWatchLaterButton(content.Title);
            }
        }
        
        function getAutoplayUrl(url) {
            if (!url) return '';
            const separator = url.includes('?') ? '&' : '?';
            if (url.includes('vidfast.pro')) {
                return url + separator + 'autoPlay=true';
            }
            return url + separator + 'autoplay=true';
        }

        // Play URL with YouTube and Vidsrc support
        async function playUrl(url, servers = []) {
            if (!url || !playerInstance) return;

            const playerContainer = document.querySelector('.player-container');
            let iframe = playerContainer.querySelector('iframe.external-content-iframe');

            // Reset dash.js player if it exists
            if (dashPlayer) {
                dashPlayer.reset();
                dashPlayer = null;
            }

            // Always stop Plyr if it's playing, before deciding what to do next.
            if (playerInstance && typeof playerInstance.stop === 'function') {
                playerInstance.stop();
            }
            // Ensure message area is hidden by default for regular playback
            elements.playerMessageArea.style.display = 'none';
            elements.playerMessageArea.textContent = '';

            const isEmbed = isGoogleDriveUrl(url) || isMegaNzUrl(url) || isVidsrcUrl(url) || isVidjoyUrl(url) || isVidsrcMeUrl(url) || isVidsrcToUrl(url) || isVidsrcXyzUrl(url) || isGoDrivePlayerUrl(url) || isVidLinkProUrl(url) || is2EmbedUrl(url) || isEmbedSuUrl(url) || isAutoEmbedUrl(url) || isVideasyUrl(url) || isVidfastEmbedUrl(url) || isVidplusUrl(url);

            if (isEmbed) {
                // Switching TO an embed source
                elements.player.style.display = 'none'; // Hide native player

                // If an iframe already exists, remove it to ensure a clean state
                if (iframe) {
                    iframe.src = 'about:blank'; // Stop content
                    iframe.remove();
                }

                // Create a new iframe for the embedded content
                iframe = document.createElement('iframe');
                iframe.className = 'external-content-iframe';
                iframe.style.position = 'absolute';
                iframe.style.top = '0';
                iframe.style.left = '0';
                iframe.style.width = '100%';
                iframe.style.height = '100%';
                iframe.style.border = 'none';
                iframe.setAttribute('allowfullscreen', '');
                iframe.setAttribute('allow', 'autoplay; encrypted-media; picture-in-picture');
                iframe.setAttribute('sandbox', 'allow-scripts allow-same-origin allow-presentation allow-forms');
                playerContainer.appendChild(iframe);

                let embedUrl = url;
                if (isGoogleDriveUrl(url)) {
                    const fileId = extractGoogleDriveId(url);
                    if (fileId) embedUrl = `https://drive.google.com/file/d/${fileId}/preview`;
                } else if (isMegaNzUrl(url)) {
                    embedUrl = url.replace('/file/', '/embed/');
                } else if (isVidfastEmbedUrl(url)) {
                    embedUrl = url.replace('/movie/', '/embed/movie/').replace('/tv/', '/embed/tv/');
                } else if (isVidplusUrl(url)) {
                    embedUrl = url.replace('https://vidplus.to', 'https://player.vidplus.to').replace('/movie/', '/embed/movie/').replace('/tv/', '/embed/tv/');
                }

                iframe.src = getAutoplayUrl(embedUrl);
                
                // Update in-page server dropdown for multiple servers
                if (servers && servers.length > 1) {
                    setTimeout(() => updateServerDropdown(servers, { url: url }), 100);
                }

                return; // Exit function after handling embed
            } else {
                // This is a direct link, ensure no iframe is present
                if (iframe) {
                    iframe.style.display = 'none'; // Hide it immediately
                    iframe.src = 'about:blank';    // Stop any loading/playing
                    iframe.remove();                // Remove from DOM
                }
                
                // Show Plyr player
                elements.player.style.display = 'block';
                
                // Hide any selectors specific to embedded players
                hideEmbeddedServerSelector();
            }

            // Check if it's a YouTube URL
            if (isYouTubeUrl(url)) {
                const videoId = extractYouTubeId(url);
                if (videoId) {
                    playerInstance.source = {
                        type: 'video',
                        sources: [{
                            src: videoId,
                            provider: 'youtube'
                        }]
                    };
                    return;
                }
            }
            // UNIVERSAL APPROACH: Check if this channel has DRM/license requirements
            if (url.includes('.mpd')) {
                // Detect channel format for better logging
                const formatInfo = detectChannelFormat(url);
                console.log(`📺 Playing ${formatInfo.type} channel (${formatInfo.quality})`);
                
                // Show loading status
                updateLoadingStatus(`Loading ${formatInfo.type} channel (${formatInfo.quality})...`);
                
                // Use universal DRM detection for any channel type
                const hasDRMRequirements = await detectChannelDRMRequirements(url, servers);
                
                if (hasDRMRequirements) {
                    console.log('🔒 Channel has DRM requirements - using Shaka Player with DRM');
                    updateLoadingStatus('Configuring DRM and loading stream...');
                    // Use Shaka Player for DRM-protected content
                    await handleMPDStream(url, servers);
                } else {
                    console.log('📺 Channel has no DRM requirements - using optimized non-DRM MPD player');
                    updateLoadingStatus('Loading stream without DRM...');
                    // Use optimized non-DRM MPD player
                    await playNonDRMMPD(url);
                    showPlayingStatus('Non-DRM MPD Stream', 'Playing Successfully');
                }
                return;
            }

            // Universal video format handling for Plyr
            const formatInfo = detectChannelFormat(url);
            console.log(`📺 Playing ${formatInfo.type} content (${formatInfo.quality}) with ${formatInfo.recommendedPlayer}`);
            
            // Determine MIME type based on format
            let mimeType = 'video/mp4'; // Default
            if (url.includes('.m3u8')) {
                mimeType = 'application/x-mpegURL';
            } else if (url.includes('.webm')) {
                mimeType = 'video/webm';
            } else if (url.includes('.avi')) {
                mimeType = 'video/x-msvideo';
            } else if (url.includes('.mkv')) {
                mimeType = 'video/x-matroska';
            }
            
            playerInstance.source = {
                type: 'video',
                sources: [{
                    src: url,
                    type: mimeType
                }]
            };
            
            // Autoplay regular video content
            setTimeout(() => {
                if (playerInstance && typeof playerInstance.play === 'function') {
                    enhancedAutoplay(elements.player, playerInstance);
                }
            }, 500);
        }

        // Enhanced MPD handling with Shaka Player only (universal channel compatibility)
        async function handleMPDStream(url, servers) {
            try {
                // Reset any existing players
                if (window.currentShakaPlayer) {
                    window.currentShakaPlayer.destroy();
                    window.currentShakaPlayer = null;
                }
                
                // Show loading message
                updateLoadingStatus('Initializing Shaka Player...');
                
                // Auto-initialize Shaka Player if not available
                if (!window.shaka) {
                    throw new Error('Shaka Player library not loaded');
                }
                
                // Install polyfills and check browser support
                if (!window.shakaPlayerInitialized) {
                    shaka.polyfill.installAll();
                    if (!shaka.Player.isBrowserSupported()) {
                        throw new Error('Browser not supported by Shaka Player. MPD streams require Shaka Player.');
                    }
                    window.shakaPlayerInitialized = true;
                    console.log('✅ Shaka Player initialized for MPD stream');
                }
                
                // Use Shaka Player exclusively for MPD files
                try {
                    await playWithShaka(url, servers);
                    showPlayingStatus('MPD Stream', 'Playing Successfully');
                    return;
                } catch (shakaError) {
                    console.error('Shaka Player failed:', shakaError);
                    // Try alternative MPD sources with Shaka Player
                    await tryAlternativeMPDSources(url, servers);
                }
                
            } catch (error) {
                console.error('MPD handling failed:', error);
                updateLoadingStatus('Failed to load MPD stream. Trying alternative sources...', true);
                
                // Try alternative sources from the same server
                await tryAlternativeMPDSources(url, servers);
            }
        }
        
        // Enhanced Shaka Player for MPD streams (universal channel compatibility)
        async function playWithShaka(url, servers) {
            return new Promise(async (resolve, reject) => {
                try {
                    const video = elements.player;
                    const player = new shaka.Player(video);
                    
                    // Store reference to current player
                    window.currentShakaPlayer = player;
                    
                    // Add error event listener
                    player.addEventListener('error', (event) => {
                        console.error('Shaka Player Error:', event.detail);
                        elements.playerMessageArea.textContent = `Error: ${event.detail.message}`;
                        elements.playerMessageArea.style.display = 'block';
                        reject(new Error(event.detail.message));
                    });
                    
                    // Add loaded event listener
                    player.addEventListener('loaded', () => {
                        console.log('✅ MPD stream loaded successfully with Shaka Player');
                        showPlayingStatus('MPD Stream', 'Loaded Successfully');
                    });
                    
                    // Configure DRM if servers have DRM information
                    if (servers && servers.length > 0) {
                        const drmServer = servers.find(s => s.drm || s.license || s.key || s.keyId);
                        
                        if (drmServer) {
                            console.log('🔒 Configuring DRM for channel:', drmServer.name || 'Unknown');
                            
                            if (drmServer.license && drmServer.license.includes(':')) {
                                // ClearKey DRM (like in shaka.html)
                                const [keyId, key] = drmServer.license.split(':');
                                const drmConfiguration = {
                                    servers: {
                                        'com.widevine.alpha': 'https://qp-pldt-live-grp-02-prod.akamaized.net/proxy/widevine'
                                    },
                                    clearKeys: {
                                        [keyId]: key
                                    }
                                };
                                player.configure({ drm: drmConfiguration });
                                console.log('✅ ClearKey DRM configured');
                            } else if (drmServer.license) {
                                // License server DRM
                                const drmConfiguration = {
                                    servers: {
                                        'com.widevine.alpha': drmServer.license
                                    }
                                };
                                player.configure({ drm: drmConfiguration });
                                console.log('✅ License server DRM configured');
                            }
                        } else {
                            console.log('ℹ️ No DRM configuration needed');
                            player.configure({ drm: null }); // Clear DRM for non-protected streams
                        }
                    } else {
                        console.log('ℹ️ No server information, treating as non-DRM');
                        player.configure({ drm: null });
                    }
                    
                    // Default streaming configuration (like in shaka.html)
                    player.configure({
                        streaming: {
                            bufferingGoal: 60,
                            rebufferingGoal: 2,
                            bufferBehind: 30,
                            ignoreTextStreamFailures: true
                        }
                    });
                    
                    // Load the manifest
                    await player.load(url);
                    
                    // Success - autoplay the stream
                    elements.playerMessageArea.style.display = 'none';
                    
                    // Autoplay the video after successful load
                    enhancedAutoplay(video);
                    
                    resolve();
                    
                } catch (error) {
                    reject(error);
                }
            });
        }
        
        // Shaka Player is the only MPD player (removed dash.js fallback)
        
        // Handle non-DRM MPD streams efficiently
        async function playNonDRMMPD(url) {
            try {
                console.log('📺 Playing non-DRM MPD stream:', url);
                
                // Reset any existing players
                if (window.currentShakaPlayer) {
                    window.currentShakaPlayer.destroy();
                    window.currentShakaPlayer = null;
                }
                
                const video = elements.player;
                const player = new shaka.Player(video);
                window.currentShakaPlayer = player;
                
                // Add error event listener
                player.addEventListener('error', (event) => {
                    console.error('Shaka Player Error:', event.detail);
                    elements.playerMessageArea.textContent = `Error: ${event.detail.message}`;
                    elements.playerMessageArea.style.display = 'block';
                });
                
                                    // Add loaded event listener
                    player.addEventListener('loaded', () => {
                        console.log('✅ Non-DRM MPD stream loaded successfully');
                        showPlayingStatus('Non-DRM MPD Stream', 'Loaded Successfully');
                    });
                
                // Clear DRM configuration for non-DRM streams
                player.configure({ drm: null });
                
                // Default streaming configuration (like in shaka.html)
                player.configure({
                    streaming: {
                        bufferingGoal: 60,
                        rebufferingGoal: 2,
                        bufferBehind: 30,
                        ignoreTextStreamFailures: true
                    }
                });
                
                // Load the manifest without DRM
                await player.load(url);
                
                console.log('✅ Non-DRM MPD stream loaded successfully');
                elements.playerMessageArea.style.display = 'none';
                
                // Autoplay the video after successful load
                enhancedAutoplay(video);
                
            } catch (error) {
                console.error('Non-DRM MPD playback failed:', error);
                elements.playerMessageArea.textContent = `Error playing stream: ${error.message}`;
                elements.playerMessageArea.style.display = 'block';
                throw error;
            }
        }
        
        // Try alternative MPD sources
        async function tryAlternativeMPDSources(originalUrl, servers) {
            if (!servers || servers.length <= 1) {
                elements.playerMessageArea.textContent = 'No alternative sources available.';
                return;
            }
            
            // Try other MPD sources from the same server list
            for (const server of servers) {
                if (server.url !== originalUrl && server.url.includes('.mpd')) {
                    try {
                        updateLoadingStatus(`Trying alternative source: ${server.name || 'Unknown'}`);
                        await handleMPDStream(server.url, servers);
                        return;
                    } catch (error) {
                        console.warn('Alternative source failed:', server.url, error);
                        continue;
                    }
                }
            }
            
            updateLoadingStatus('All MPD sources failed. Please try again later.', true);
        }
        
        // Detect DRM protection in MPD manifest
        async function detectDRMProtection(mpdUrl) {
            try {
                const response = await fetch(mpdUrl);
                const manifest = await response.text();
                
                // Check for DRM indicators in the manifest
                const hasWidevine = manifest.includes('urn:uuid:EDEF8BA9-79D6-4ACE-A3C8-27DCD51D21ED');
                const hasPlayReady = manifest.includes('urn:uuid:9A04F079-9840-4286-AB92-E65BE0885F95');
                const hasFairPlay = manifest.includes('urn:uuid:94CE86FB-07FF-4F43-ADB8-93D2FA968CA2');
                
                return {
                    hasDRM: hasWidevine || hasPlayReady || hasFairPlay,
                    widevine: hasWidevine,
                    playReady: hasPlayReady,
                    fairPlay: hasFairPlay
                };
            } catch (error) {
                console.warn('Could not detect DRM protection:', error);
                return { hasDRM: false };
            }
        }
        
        // Universal channel format detection and handling
        function detectChannelFormat(url) {
            const formatInfo = {
                type: 'unknown',
                isLive: false,
                hasDRM: false,
                quality: 'unknown',
                recommendedPlayer: 'unknown'
            };
            
            // Detect format type
            if (url.includes('.mpd')) {
                formatInfo.type = 'MPD';
                formatInfo.recommendedPlayer = 'Shaka Player';
            } else if (url.includes('.m3u8')) {
                formatInfo.type = 'HLS';
                formatInfo.recommendedPlayer = 'Plyr (HLS.js)';
            } else if (url.includes('.mp4')) {
                formatInfo.type = 'MP4';
                formatInfo.recommendedPlayer = 'Plyr';
            } else if (url.includes('.webm')) {
                formatInfo.type = 'WebM';
                formatInfo.recommendedPlayer = 'Plyr';
            } else if (url.includes('.avi')) {
                formatInfo.type = 'AVI';
                formatInfo.recommendedPlayer = 'Plyr';
            } else if (url.includes('.mkv')) {
                formatInfo.type = 'MKV';
                formatInfo.recommendedPlayer = 'Plyr';
            }
            
            // Detect if it's likely a live stream
            if (url.includes('live') || url.includes('stream') || url.includes('tv') || 
                url.includes('broadcast') || url.includes('channel')) {
                formatInfo.isLive = true;
            }
            
            // Detect quality from URL
            if (url.includes('1080p') || url.includes('1080')) {
                formatInfo.quality = '1080p';
            } else if (url.includes('720p') || url.includes('720')) {
                formatInfo.quality = '720p';
            } else if (url.includes('480p') || url.includes('480')) {
                formatInfo.quality = '480p';
            } else if (url.includes('360p') || url.includes('360')) {
                formatInfo.quality = '360p';
            }
            
            console.log('🔍 Channel format detected:', formatInfo);
            return formatInfo;
        }

        // Show current playing status (like in shaka.html)
        function showPlayingStatus(channelName, status = 'Playing') {
            if (elements.playerMessageArea) {
                elements.playerMessageArea.textContent = `${status}: ${channelName}`;
                elements.playerMessageArea.style.display = 'block';
                elements.playerMessageArea.style.color = '#4CAF50';
                elements.playerMessageArea.style.backgroundColor = 'rgba(76, 175, 80, 0.1)';
            }
        }

        // Show play button when autoplay fails
        function showPlayButton() {
            if (elements.playerMessageArea) {
                elements.playerMessageArea.innerHTML = `
                    <div style="text-align: center; padding: 20px;">
                        <p>Click to start playback:</p>
                        <button onclick="startPlayback()" style="
                            background: #ff5722; 
                            color: white; 
                            border: none; 
                            padding: 10px 20px; 
                            border-radius: 5px; 
                            cursor: pointer;
                            font-size: 16px;
                        ">▶️ Play</button>
                    </div>
                `;
                elements.playerMessageArea.style.display = 'block';
                elements.playerMessageArea.style.color = '#ffffff';
                elements.playerMessageArea.style.backgroundColor = 'rgba(255, 87, 34, 0.1)';
            }
        }

        // Start playback manually
        function startPlayback() {
            if (window.currentShakaPlayer) {
                // For Shaka Player
                const video = elements.player;
                video.play().catch(error => console.log('Manual play failed:', error));
            } else if (playerInstance) {
                // For regular video player
                playerInstance.play().catch(error => console.log('Manual play failed:', error));
            }
        }

        // Enhanced autoplay with mobile detection
        function enhancedAutoplay(videoElement, playerInstance = null) {
            // Check if it's a mobile device
            const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
            
            if (isMobile) {
                // On mobile, try muted autoplay first (more likely to work)
                videoElement.muted = true;
                videoElement.play().then(() => {
                    console.log('✅ Mobile muted autoplay successful');
                    // Unmute after successful autoplay
                    setTimeout(() => {
                        videoElement.muted = false;
                    }, 100);
                }).catch(error => {
                    console.log('Mobile muted autoplay failed:', error);
                    // Show play button for manual start
                    showPlayButton();
                });
            } else {
                // On desktop, try normal autoplay
                videoElement.play().then(() => {
                    console.log('✅ Desktop autoplay successful');
                }).catch(error => {
                    console.log('Desktop autoplay failed:', error);
                    showPlayButton();
                });
            }
        }

        // Enhanced server selection with universal DRM awareness
        function selectOptimalServer(servers) {
            if (!servers || servers.length === 0) return null;
            
            // Analyze all servers for DRM capabilities
            servers.forEach(server => {
                if (server.url.includes('.mpd')) {
                    // Check if server has explicit DRM configuration
                    if (server.drm || server.license || server.key || server.keyId) {
                        server.hasDRMConfig = true;
                        console.log(`🔒 Server has DRM config: ${server.name || 'Unknown'}`);
                    } else {
                        server.hasDRMConfig = false;
                        console.log(`🔓 Server has no DRM config: ${server.name || 'Unknown'}`);
                    }
                    
                    // Mark as MPD for Shaka Player handling
                    server.isMPD = true;
                }
            });
            
            // Prioritize servers based on multiple factors
            const sortedServers = servers.sort((a, b) => {
                // First priority: MPD streams (require Shaka Player)
                if (a.isMPD && !b.isMPD) return -1;
                if (!a.isMPD && b.isMPD) return 1;
                
                // Second priority: DRM support if available
                if (a.hasDRMConfig && !b.hasDRMConfig) return -1;
                if (!a.hasDRMConfig && b.hasDRMConfig) return 1;
                
                // Third priority: Quality (higher resolution first)
                const aQuality = parseInt(a.name?.replace(/\D/g, '') || '0');
                const bQuality = parseInt(b.name?.replace(/\D/g, '') || '0');
                if (aQuality !== bQuality) return bQuality - aQuality;
                
                // Fourth priority: Server reliability (prefer servers with more info)
                const aInfo = Object.keys(a).length;
                const bInfo = Object.keys(b).length;
                if (aInfo !== bInfo) return bInfo - aInfo;
                
                return 0;
            });
            
            const selectedServer = sortedServers[0];
            console.log(`🎯 Selected optimal server: ${selectedServer.name || 'Unknown'} (MPD: ${selectedServer.isMPD}, DRM: ${selectedServer.hasDRMConfig})`);
            
            return selectedServer;
        }
        
        // Update the compact in-page server dropdown
        function updateServerDropdown(servers, currentServer = null) {
            if (!elements.serverSelector || !elements.serverSelectorContainer) return;
            
            // Remove any floating selectors if present
            const existingUniversal = document.getElementById('universal-server-selector');
            if (existingUniversal && existingUniversal.parentNode) {
                existingUniversal.parentNode.removeChild(existingUniversal);
            }
            const existingEmbedded = document.getElementById('embedded-server-selector');
            if (existingEmbedded && existingEmbedded.parentNode) {
                existingEmbedded.parentNode.removeChild(existingEmbedded);
            }
            
            elements.serverSelector.innerHTML = '';
            
            if (!servers || servers.length <= 1) {
                elements.serverSelectorContainer.style.display = 'none';
                return;
            }
            
            servers.forEach((s, idx) => {
                const option = document.createElement('option');
                option.value = s.url;
                option.textContent = s.name || `Server ${idx + 1}`;
                if (currentServer && (s.url === currentServer.url)) {
                    option.selected = true;
                }
                elements.serverSelector.appendChild(option);
            });
            
            elements.serverSelectorContainer.style.display = 'block';
            
            // Rebind change handler
            elements.serverSelector.onchange = (e) => {
                const selectedUrl = e.target.value;
                const selected = servers.find(s => s.url === selectedUrl);
                if (selected) {
                    switchToServer(selected, servers);
                }
            };
        }
        
        // Create universal server selector for both Plyr and embedded sources
        function createUniversalServerSelector(servers, currentServer = null) {
            if (!servers || servers.length <= 1) return; // No need for selector if only one server
            
            // Remove existing server selector if it exists
            const existingSelector = document.getElementById('universal-server-selector');
            if (existingSelector) {
                existingSelector.remove();
            }
            
            // Create server selector container
            const selectorContainer = document.createElement('div');
            selectorContainer.id = 'universal-server-selector';
            selectorContainer.style.cssText = `
                position: absolute;
                top: 10px;
                right: 10px;
                z-index: 1000;
                background: rgba(0, 0, 0, 0.9);
                border-radius: 12px;
                padding: 15px;
                min-width: 250px;
                max-width: 90vw;
                backdrop-filter: blur(15px);
                border: 1px solid rgba(255, 255, 255, 0.2);
                box-shadow: 0 8px 32px rgba(0, 0, 0, 0.6);
                max-height: 400px;
                overflow-y: auto;
            `;
            // Hide by default; show via the indicator button
            selectorContainer.style.display = 'none';
            // Compact sizing on small screens
            if (window.innerWidth <= 768) {
                selectorContainer.style.minWidth = '180px';
                selectorContainer.style.maxWidth = '85vw';
                selectorContainer.style.maxHeight = '50vh';
                selectorContainer.style.padding = '12px';
                selectorContainer.style.top = '8px';
                selectorContainer.style.right = '8px';
            }
            
            // Create title
            const title = document.createElement('div');
            title.style.cssText = `
                color: white;
                font-size: 16px;
                font-weight: bold;
                margin-bottom: 15px;
                text-align: center;
                padding-bottom: 10px;
                border-bottom: 1px solid rgba(255, 255, 255, 0.2);
            `;
            title.textContent = '🌐 Server Selection';
            selectorContainer.appendChild(title);
            
            // Create server list
            const serverList = document.createElement('div');
            serverList.style.cssText = `
                display: flex;
                flex-direction: column;
                gap: 8px;
            `;
            
            servers.forEach((server, index) => {
                const serverItem = document.createElement('div');
                serverItem.style.cssText = `
                    padding: 12px 16px;
                    background: rgba(255, 255, 255, 0.1);
                    border-radius: 8px;
                    cursor: pointer;
                    transition: all 0.3s ease;
                    color: white;
                    font-size: 14px;
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    border: 2px solid transparent;
                `;
                
                // Highlight current server
                const isCurrentServer = currentServer && (
                    (currentServer.url && server.url === currentServer.url) ||
                    (currentServer.src && server.url === currentServer.src)
                );
                
                if (isCurrentServer) {
                    serverItem.style.background = 'rgba(59, 130, 246, 0.6)';
                    serverItem.style.border = '2px solid rgba(59, 130, 246, 0.8)';
                }
                
                // Server name with quality indicator
                const serverName = document.createElement('span');
                serverName.style.cssText = `
                    display: flex;
                    align-items: center;
                    gap: 8px;
                `;
                
                // Add quality icon based on server name
                let qualityIcon = '📺';
                if (server.name) {
                    if (server.name.includes('1080p') || server.name.includes('1080')) {
                        qualityIcon = '🎬';
                    } else if (server.name.includes('720p') || server.name.includes('720')) {
                        qualityIcon = '📺';
                    } else if (server.name.includes('480p') || server.name.includes('480')) {
                        qualityIcon = '📱';
                    } else if (server.name.includes('360p') || server.name.includes('360')) {
                        qualityIcon = '📱';
                    }
                }
                
                serverName.innerHTML = `${qualityIcon} ${server.name || `Server ${index + 1}`}`;
                serverItem.appendChild(serverName);
                
                // Server status indicator
                const statusIndicator = document.createElement('span');
                statusIndicator.style.cssText = `
                    width: 10px;
                    height: 10px;
                    border-radius: 50%;
                    background: #10b981;
                    margin-left: 12px;
                    box-shadow: 0 0 8px rgba(16, 185, 129, 0.6);
                `;
                serverItem.appendChild(statusIndicator);
                
                // Add hover effects
                serverItem.addEventListener('mouseenter', () => {
                    if (!isCurrentServer) {
                        serverItem.style.background = 'rgba(255, 255, 255, 0.2)';
                        serverItem.style.transform = 'translateX(3px)';
                    }
                });
                
                serverItem.addEventListener('mouseleave', () => {
                    if (isCurrentServer) {
                        serverItem.style.background = 'rgba(59, 130, 246, 0.6)';
                    } else {
                        serverItem.style.background = 'rgba(255, 255, 255, 0.1)';
                    }
                    serverItem.style.transform = 'translateX(0)';
                });
                
                // Add click handler to switch servers
                serverItem.addEventListener('click', () => {
                    if (!isCurrentServer) {
                        switchToServer(server, servers);
                        
                        // Update visual selection
                        document.querySelectorAll('#universal-server-selector .server-item').forEach(item => {
                            item.style.background = 'rgba(255, 255, 255, 0.1)';
                            item.style.border = '2px solid transparent';
                        });
                        serverItem.style.background = 'rgba(59, 130, 246, 0.6)';
                        serverItem.style.border = '2px solid rgba(59, 130, 246, 0.8)';
                        
                        // Show feedback
                        showServerSwitchFeedback(server.name || `Server ${index + 1}`);
                    }
                });
                
                // Add class for easy selection
                serverItem.className = 'server-item';
                serverList.appendChild(serverItem);
            });
            
            selectorContainer.appendChild(serverList);
            
            // Add close button
            const closeButton = document.createElement('button');
            closeButton.style.cssText = `
                position: absolute;
                top: 8px;
                right: 8px;
                background: rgba(255, 255, 255, 0.1);
                border: none;
                color: white;
                font-size: 18px;
                cursor: pointer;
                padding: 4px 8px;
                border-radius: 6px;
                transition: all 0.2s ease;
                width: 32px;
                height: 32px;
                display: flex;
                align-items: center;
                justify-content: center;
            `;
            closeButton.innerHTML = '×';
            closeButton.addEventListener('click', () => {
                selectorContainer.style.display = 'none';
            });
            closeButton.addEventListener('mouseenter', () => {
                closeButton.style.background = 'rgba(255, 255, 255, 0.2)';
                closeButton.style.transform = 'scale(1.1)';
            });
            closeButton.addEventListener('mouseleave', () => {
                closeButton.style.background = 'rgba(255, 255, 255, 0.1)';
                closeButton.style.transform = 'scale(1)';
            });
            selectorContainer.appendChild(closeButton);
            
            // Add to player container
            const playerContainer = document.querySelector('.player-container') || document.getElementById('player-container');
            if (playerContainer) {
                playerContainer.appendChild(selectorContainer);
                
                // Add a floating indicator button
                const indicatorButton = document.createElement('div');
                indicatorButton.id = 'server-selector-indicator';
                indicatorButton.style.cssText = `
                    position: absolute;
                    top: 10px;
                    left: 10px;
                    background: rgba(59, 130, 246, 0.9);
                    color: white;
                    padding: 8px 12px;
                    border-radius: 20px;
                    font-size: 12px;
                    cursor: pointer;
                    z-index: 999;
                    backdrop-filter: blur(10px);
                    border: 1px solid rgba(255, 255, 255, 0.3);
                    transition: all 0.3s ease;
                    opacity: 0.8;
                    font-weight: 500;
                    box-shadow: 0 4px 16px rgba(59, 130, 246, 0.4);
                `;
                indicatorButton.innerHTML = `🌐 ${servers.length} Servers`;
                indicatorButton.title = 'Click to show server selector';
                
                indicatorButton.addEventListener('click', () => {
                    selectorContainer.style.display = 'block';
                    selectorContainer.style.opacity = '1';
                });
                
                indicatorButton.addEventListener('mouseenter', () => {
                    indicatorButton.style.opacity = '1';
                    indicatorButton.style.transform = 'scale(1.05)';
                });
                
                indicatorButton.addEventListener('mouseleave', () => {
                    indicatorButton.style.opacity = '0.8';
                    indicatorButton.style.transform = 'scale(1)';
                });
                
                playerContainer.appendChild(indicatorButton);
            }
        }
        
        // Function to switch between servers
async function switchToServer(server, allServers) {
    try {
        // Stop current playback more reliably
        if (playerInstance) {
            playerInstance.pause();
            playerInstance.stop();
        }
        
        // Reset Shaka Player if it exists
        if (window.currentShakaPlayer) {
            window.currentShakaPlayer.destroy();
            window.currentShakaPlayer = null;
        }
        
        // Remove any existing embedded iframes
        const playerContainer = document.querySelector('.player-container');
        const existingIframe = playerContainer.querySelector('iframe.external-content-iframe');
        if (existingIframe) {
            existingIframe.remove();
        }
        
        // Always show the Plyr player initially
        elements.player.style.display = 'block';
        
        // Check if this is an embedded source
        const isEmbed = isVidsrcUrl(server.url) || isVidjoyUrl(server.url) || 
                       isVidsrcMeUrl(server.url) || isVidsrcToUrl(server.url) || 
                       isVidsrcXyzUrl(server.url) || isGoDrivePlayerUrl(server.url) || 
                       isVidLinkProUrl(server.url) || is2EmbedUrl(server.url) || 
                       isEmbedSuUrl(server.url) || isAutoEmbedUrl(server.url) || 
                       isVideasyUrl(server.url) || isMegaNzUrl(server.url) || 
                       isGoogleDriveUrl(server.url);

        if (isEmbed) {
            // Hide Plyr player for embedded content
            elements.player.style.display = 'none';
            
            // Create iframe for embedded content
            const iframe = document.createElement('iframe');
            iframe.className = 'external-content-iframe';
            iframe.style.cssText = `
                position: absolute;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                border: none;
            `;
            iframe.setAttribute('allowfullscreen', '');
            iframe.setAttribute('allow', 'autoplay; encrypted-media; picture-in-picture');
            
            let embedUrl = server.url;
            if (isGoogleDriveUrl(server.url)) {
                const fileId = extractGoogleDriveId(server.url);
                if (fileId) embedUrl = `https://drive.google.com/file/d/${fileId}/preview`;
            } else if (isMegaNzUrl(server.url)) {
                embedUrl = server.url.replace('/file/', '/embed/');
            }
            
            iframe.src = getAutoplayUrl(embedUrl);
            playerContainer.appendChild(iframe);
        } else {
            // For direct links, use the standard player
            await playUrl(server.url, allServers);
        }
        
        // Update current server tracking
        window.currentServer = server;
        if (elements.serverSelector) {
            elements.serverSelector.value = server.url;
        }
        
        // Show feedback to user
        showServerSwitchFeedback(server.name || `Server ${allServers.indexOf(server) + 1}`);
        
    } catch (error) {
        console.error('Error switching to server:', error);
        showServerSwitchFeedback('Error switching server', 'error');
    }
}
        
        // Enhanced function to show server switch feedback
        function showServerSwitchFeedback(message, type = 'success') {
            const feedbackDiv = document.createElement('div');
            feedbackDiv.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                background: ${type === 'success' ? 'rgba(16, 185, 129, 0.9)' : 'rgba(239, 68, 68, 0.9)'};
                color: white;
                padding: 12px 20px;
                border-radius: 8px;
                font-size: 14px;
                font-weight: 500;
                z-index: 10000;
                backdrop-filter: blur(10px);
                border: 1px solid rgba(255, 255, 255, 0.2);
                box-shadow: 0 4px 16px rgba(0, 0, 0, 0.3);
                transform: translateX(100%);
                transition: transform 0.3s ease;
            `;
            feedbackDiv.textContent = message;
            
            document.body.appendChild(feedbackDiv);
            
            // Animate in
            setTimeout(() => {
                feedbackDiv.style.transform = 'translateX(0)';
            }, 100);
            
            // Remove after 3 seconds
            setTimeout(() => {
                feedbackDiv.style.transform = 'translateX(100%)';
                setTimeout(() => {
                    if (feedbackDiv.parentNode) {
                        feedbackDiv.parentNode.removeChild(feedbackDiv);
                    }
                }, 300);
            }, 3000);
        }
        
        // Function to automatically try next server if current one fails
        async function tryNextServer(servers, currentServerIndex = 0) {
            if (!servers || servers.length <= 1) return false;
            
            const nextIndex = (currentServerIndex + 1) % servers.length;
            const nextServer = servers[nextIndex];
            
            try {
                showServerSwitchFeedback(`Trying next server: ${nextServer.name || 'Unknown'}`);
                await switchToServer(nextServer, servers);
                return true;
            } catch (error) {
                console.error('Failed to switch to next server:', error);
                if (nextIndex !== 0) { // Don't loop infinitely
                    return await tryNextServer(servers, nextIndex);
                }
                return false;
            }
        }
        
        // Add keyboard shortcuts for server selector
        document.addEventListener('keydown', (event) => {
            // Ctrl+S to toggle server selector
            if (event.ctrlKey && event.key === 's') {
                event.preventDefault();
                const selector = document.getElementById('universal-server-selector');
                if (selector) {
                    if (selector.style.display === 'none') {
                        selector.style.display = 'block';
                        selector.style.opacity = '1';
                    } else {
                        selector.style.display = 'none';
                    }
                }
            }
            
            // Escape key to close server selector
            if (event.key === 'Escape') {
                const selector = document.getElementById('universal-server-selector');
                if (selector) {
                    selector.style.display = 'none';
                }
            }
        });
        
        // Automatic fallback between DRM and non-DRM sources with auto-Shaka loading
        async function handleAutomaticFallback(originalUrl, servers, drmFailed = false) {
            if (!servers || servers.length <= 1) {
                elements.playerMessageArea.textContent = 'No alternative sources available for fallback.';
                return false;
            }
            
            // If DRM failed, try non-DRM sources first
            let fallbackServers = servers;
            if (drmFailed) {
                fallbackServers = servers.filter(s => !s.drm && !s.url.includes('.mpd'));
                if (fallbackServers.length === 0) {
                    fallbackServers = servers; // Fall back to all servers if no non-DRM available
                }
            }
            
            // Try alternative sources
            for (const server of fallbackServers) {
                if (server.url === originalUrl) continue;
                
                try {
                    elements.playerMessageArea.textContent = `Trying fallback source: ${server.name || 'Unknown'}`;
                    
                    if (server.url.includes('.mpd')) {
                        // Auto-load Shaka Player for MPD fallback
                        await autoLoadShakaPlayer(server.url, servers);
                    } else {
                        // Try regular video source
                        playerInstance.source = {
                            type: 'video',
                            sources: [{
                                src: server.url,
                                type: server.url.includes('m3u8') ? 'application/x-mpegURL' : 'video/mp4'
                            }]
                        };
                    }
                    
                    console.log(`✅ Fallback successful: ${server.url}`);
                    return true;
                    
                } catch (error) {
                    console.warn(`⚠️ Fallback failed: ${server.url}`, error);
                    continue;
                }
            }
            
            elements.playerMessageArea.textContent = 'All fallback sources failed.';
            return false;
        }
        
        // Auto-load Shaka Player for MPD streams
        async function autoLoadShakaPlayer(url, servers) {
            try {
                // Ensure Shaka Player is available
                if (!window.shaka) {
                    throw new Error('Shaka Player not available');
                }
                
                // Initialize Shaka Player if not already done
                if (!window.shakaPlayerInstance) {
                    shaka.polyfill.installAll();
                    if (!shaka.Player.isBrowserSupported()) {
                        throw new Error('Browser not supported by Shaka Player');
                    }
                    console.log('✅ Shaka Player initialized for fallback');
                }
                
                // Create new player instance for fallback
                const video = elements.player;
                const player = new shaka.Player(video);
                window.shakaPlayerInstance = player;
                
                // Auto-detect and configure DRM for known streams
                const drmConfig = await autoDetectDRMConfig(url, servers);
                if (drmConfig) {
                    player.configure({ drm: drmConfig });
                    console.log('✅ DRM configured for fallback stream');
                }
                
                // Configure streaming for fallback
                player.configure({
                    streaming: {
                        bufferingGoal: 20,        // Fast loading for fallback
                        rebufferingGoal: 1,       // Minimal rebuffering
                        bufferBehind: 10,         // Reduced buffer
                        ignoreTextStreamFailures: true,
                        retryParameters: {
                            maxAttempts: 3,
                            baseDelay: 500,
                            backoffFactor: 1.5
                        }
                    }
                });
                
                // Error handling for fallback
                player.addEventListener('error', (event) => {
                    console.error('Shaka Player fallback error:', event.detail);
                    elements.playerMessageArea.textContent = `Fallback stream error: ${event.detail.message}`;
                });
                
                // Success event
                player.addEventListener('loaded', () => {
                    console.log('✅ Fallback MPD stream loaded successfully');
                    elements.playerMessageArea.style.display = 'none';
                });
                
                // Load the fallback manifest
                await player.load(url);
                console.log('✅ Fallback stream loaded with Shaka Player');
                
            } catch (error) {
                console.error('Auto-load Shaka Player failed:', error);
                throw error;
            }
        }
        
        // Auto-detect DRM configuration for any channel stream
        async function autoDetectDRMConfig(url, servers) {
            try {
                console.log('🔍 Auto-detecting DRM configuration for:', url);
                
                // First, try to fetch and analyze the MPD manifest to detect DRM
                try {
                    const response = await fetch(url);
                    const manifest = await response.text();
                    
                    // Detect DRM systems from manifest content
                    const hasWidevine = manifest.includes('urn:uuid:EDEF8BA9-79D6-4ACE-A3C8-27DCD51D21ED');
                    const hasPlayReady = manifest.includes('urn:uuid:9A04F079-9840-4286-AB92-E65BE0885F95');
                    const hasFairPlay = manifest.includes('urn:uuid:94CE86FB-07FF-4F43-ADB8-93D2FA968CA2');
                    const hasClearKey = manifest.includes('urn:uuid:1077EFEC-C0B2-4D02-8CB6-C05D0FDC7B92');
                    
                    console.log('📋 Manifest analysis:', {
                        widevine: hasWidevine,
                        playReady: hasPlayReady,
                        fairPlay: hasFairPlay,
                        clearKey: hasClearKey
                    });
                    
                    // If DRM is detected in manifest, configure the best available system
                    if (hasWidevine || hasPlayReady || hasFairPlay || hasClearKey) {
                        const drmConfig = await selectBestDRMSystem(hasWidevine, hasPlayReady, hasFairPlay, hasClearKey);
                        console.log('✅ DRM detected in manifest, configured for:', Object.keys(drmConfig.servers));
                        return drmConfig;
                    }
                    
                } catch (manifestError) {
                    console.warn('Could not analyze manifest, falling back to server info:', manifestError);
                }
                
                // Fallback: Check servers for DRM configuration
                if (servers && servers.length > 0) {
                    // Look for any server with DRM info
                    const drmServer = servers.find(s => s.drm || s.license || s.key || s.keyId);
                    
                    if (drmServer) {
                        console.log('🔑 DRM info found in server config:', drmServer);
                        
                        const drmConfig = await createDRMConfigFromServer(drmServer);
                        console.log('✅ DRM configured from server info');
                        return drmConfig;
                    }
                }
                
                // No DRM detected - this is a non-DRM stream
                console.log('ℹ️ No DRM detected - treating as non-DRM stream');
                return null;
                
            } catch (error) {
                console.warn('DRM auto-detection failed:', error);
                return null;
            }
        }
        
        // Select the best DRM system for the current browser
        async function selectBestDRMSystem(hasWidevine, hasPlayReady, hasFairPlay, hasClearKey) {
            const drmConfig = { servers: {} };
            
            // Check browser capabilities and select the best available DRM
            if (hasWidevine && await isWidevineSupported()) {
                drmConfig.servers['com.widevine.alpha'] = 'https://qp-pldt-live-grp-02-prod.akamaized.net/proxy/widevine';
                console.log('🎯 Selected Widevine DRM (best compatibility)');
            } else if (hasPlayReady && await isPlayReadySupported()) {
                drmConfig.servers['com.microsoft.playready'] = 'https://qp-pldt-live-grp-02-prod.akamaized.net/proxy/playready';
                console.log('🎯 Selected PlayReady DRM');
            } else if (hasFairPlay && await isFairPlaySupported()) {
                drmConfig.servers['com.apple.fairplay'] = 'https://qp-pldt-live-grp-02-prod.akamaized.net/proxy/fairplay';
                console.log('🎯 Selected FairPlay DRM');
            } else if (hasClearKey) {
                drmConfig.servers['org.w3.clearkey'] = 'https://qp-pldt-live-grp-02-prod.akamaized.net/proxy/clearkey';
                console.log('🎯 Selected ClearKey DRM (fallback)');
            }
            
            return drmConfig;
        }
        
        // Create DRM config from server information
        async function createDRMConfigFromServer(drmServer) {
            const drmConfig = { servers: {} };
            
            if (drmServer.license) {
                // Use provided license server with type detection
                if (drmServer.drmType === 'playready') {
                    drmConfig.servers['com.microsoft.playready'] = drmServer.license;
                } else if (drmServer.drmType === 'fairplay') {
                    drmConfig.servers['com.apple.fairplay'] = drmServer.license;
                } else if (drmServer.drmType === 'clearkey') {
                    drmConfig.servers['org.w3.clearkey'] = drmServer.license;
                } else {
                    // Default to Widevine
                    drmConfig.servers['com.widevine.alpha'] = drmServer.license;
                }
            } else {
                // Use default license servers based on browser support
                if (await isWidevineSupported()) {
                    drmConfig.servers['com.widevine.alpha'] = 'https://qp-pldt-live-grp-02-prod.akamaized.net/proxy/widevine';
                }
                if (await isPlayReadySupported()) {
                    drmConfig.servers['com.microsoft.playready'] = 'https://qp-pldt-live-grp-02-prod.akamaized.net/proxy/playready';
                }
            }
            
            // Handle clear key DRM if available
            if (drmServer.key && drmServer.keyId) {
                drmConfig.clearKeys = {
                    [drmServer.keyId]: drmServer.key
                };
                console.log('🔐 Clear key DRM configured for:', drmServer.keyId);
            }
            
            return drmConfig;
        }
        
        // Browser DRM support detection
        async function isWidevineSupported() {
            try {
                return await navigator.requestMediaKeySystemAccess('com.widevine.alpha', [{
                    initDataTypes: ['cenc'],
                    audioCapabilities: [{ contentType: 'audio/mp4;codecs="mp4a.40.2"' }],
                    videoCapabilities: [{ contentType: 'video/mp4;codecs="avc1.42E01E"' }]
                }]);
            } catch (e) {
                return false;
            }
        }
        
        async function isPlayReadySupported() {
            try {
                return await navigator.requestMediaKeySystemAccess('com.microsoft.playready', [{
                    initDataTypes: ['cenc'],
                    audioCapabilities: [{ contentType: 'audio/mp4;codecs="mp4a.40.2"' }],
                    videoCapabilities: [{ contentType: 'video/mp4;codecs="avc1.42E01E"' }]
                }]);
            } catch (e) {
                return false;
            }
        }
        
        async function isFairPlaySupported() {
            // FairPlay is typically only available on Safari/macOS
            return navigator.userAgent.includes('Safari') && !navigator.userAgent.includes('Chrome');
        }
        
        // Universal DRM configuration for any channel type
        async function configureUniversalDRM(player, url, servers) {
            try {
                console.log('🌐 Configuring universal DRM for channel:', url);
                
                // Auto-detect channel type and characteristics
                const channelInfo = await detectChannelType(url);
                console.log('📺 Channel detected as:', channelInfo.type);
                
                // Get DRM configuration
                const drmConfig = await autoDetectDRMConfig(url, servers);
                
                if (drmConfig && Object.keys(drmConfig.servers).length > 0) {
                    // Apply DRM configuration
                    player.configure({ drm: drmConfig });
                    
                    // Log what DRM systems are configured
                    const drmSystems = Object.keys(drmConfig.servers);
                    console.log(`✅ DRM configured for: ${drmSystems.join(', ')}`);
                    
                    // Add additional DRM-specific configurations
                    if (drmConfig.servers['com.widevine.alpha']) {
                        console.log('🔒 Widevine DRM enabled');
                    }
                    if (drmConfig.servers['com.microsoft.playready']) {
                        console.log('🔒 PlayReady DRM enabled');
                    }
                    if (drmConfig.servers['com.apple.fairplay']) {
                        console.log('🔒 FairPlay DRM enabled');
                    }
                    if (drmConfig.servers['org.w3.clearkey']) {
                        console.log('🔒 ClearKey DRM enabled');
                    }
                    
                    return true;
                } else {
                    console.log('ℹ️ No DRM configuration needed - treating as non-DRM stream');
                    return false;
                }
                
            } catch (error) {
                console.error('Universal DRM configuration failed:', error);
                return false;
            }
        }
        
        // Auto-detect channel type and characteristics
        async function detectChannelType(url) {
            try {
                const channelInfo = {
                    type: 'unknown',
                    isLive: false,
                    hasDRM: false,
                    platform: 'unknown',
                    quality: 'unknown'
                };
                
                // Universal platform detection for any channel
                if (url.includes('akamaized.net')) {
                    channelInfo.platform = 'Akamai';
                    channelInfo.isLive = true;
                } else if (url.includes('amagi.tv')) {
                    channelInfo.platform = 'Amagi';
                    channelInfo.isLive = true;
                } else if (url.includes('cloudfront.net')) {
                    channelInfo.platform = 'CloudFront';
                    channelInfo.isLive = true;
                } else if (url.includes('fastly.net')) {
                    channelInfo.platform = 'Fastly';
                    channelInfo.isLive = true;
                } else if (url.includes('bunny.net')) {
                    channelInfo.platform = 'Bunny';
                    channelInfo.isLive = true;
                } else if (url.includes('cdn77.org')) {
                    channelInfo.platform = 'CDN77';
                    channelInfo.isLive = true;
                } else if (url.includes('limelight.com')) {
                    channelInfo.platform = 'Limelight';
                    channelInfo.isLive = true;
                } else if (url.includes('level3.com')) {
                    channelInfo.platform = 'Level3';
                    channelInfo.isLive = true;
                } else if (url.includes('edgecast.com')) {
                    channelInfo.platform = 'EdgeCast';
                    channelInfo.isLive = true;
                } else if (url.includes('live') || url.includes('stream') || url.includes('tv')) {
                    // Generic detection for live content
                    channelInfo.platform = 'Live Stream';
                    channelInfo.isLive = true;
                }
                
                // Detect quality from URL or server name
                if (url.includes('1080p') || url.includes('1080')) {
                    channelInfo.quality = '1080p';
                } else if (url.includes('720p') || url.includes('720')) {
                    channelInfo.quality = '720p';
                } else if (url.includes('480p') || url.includes('480')) {
                    channelInfo.quality = '480p';
                } else if (url.includes('360p') || url.includes('360')) {
                    channelInfo.quality = '360p';
                }
                
                // Determine channel type based on characteristics
                if (channelInfo.isLive && channelInfo.platform !== 'unknown') {
                    channelInfo.type = 'Live TV Channel';
                } else if (url.includes('.mpd')) {
                    channelInfo.type = 'MPD Stream';
                } else if (url.includes('.m3u8')) {
                    channelInfo.type = 'HLS Stream';
                } else if (url.includes('.mp4')) {
                    channelInfo.type = 'MP4 Video';
                }
                
                console.log('🔍 Channel analysis:', channelInfo);
                return channelInfo;
                
            } catch (error) {
                console.warn('Channel type detection failed:', error);
                return { type: 'unknown', isLive: false, hasDRM: false, platform: 'unknown', quality: 'unknown' };
            }
        }
        
        // Update loading status with proper indicators
        function updateLoadingStatus(message, isError = false) {
            if (elements.playerMessageArea) {
                elements.playerMessageArea.textContent = message;
                elements.playerMessageArea.style.display = 'block';
                
                if (isError) {
                    elements.playerMessageArea.style.color = '#ff5722';
                    elements.playerMessageArea.style.backgroundColor = 'rgba(255, 87, 34, 0.1)';
                } else {
                    elements.playerMessageArea.style.color = '#ffffff';
                    elements.playerMessageArea.style.backgroundColor = 'rgba(255, 255, 255, 0.1)';
                }
            }
        }

        // Universal DRM detection for any channel
        async function detectChannelDRMRequirements(url, servers = []) {
            try {
                // First check if servers array has explicit DRM information
                if (servers && servers.length > 0) {
                    const hasExplicitDRM = servers.some(server => 
                        server.drm || 
                        server.license || 
                        server.key || 
                        server.keyId ||
                        server.drmType
                    );
                    
                    if (hasExplicitDRM) {
                        console.log('✅ DRM requirements detected from server configuration');
                        return true;
                    }
                }

                // If no explicit DRM info, analyze MPD manifest for DRM indicators
                if (url.includes('.mpd')) {
                    try {
                        const response = await fetch(url);
                        const manifest = await response.text();
                        
                        // Check for DRM indicators in the manifest
                        const hasWidevine = manifest.includes('urn:uuid:EDEF8BA9-79D6-4ACE-A3C8-27DCD51D21ED');
                        const hasPlayReady = manifest.includes('urn:uuid:9A04F079-9840-4286-AB92-E65BE0885F95');
                        const hasFairPlay = manifest.includes('urn:uuid:94CE86FB-07FF-4F43-ADB8-93D2FA968CA2');
                        const hasClearKey = manifest.includes('urn:uuid:1077EFEC-C0B2-4D02-8CB6-C05D0FDC7B92');
                        
                        if (hasWidevine || hasPlayReady || hasFairPlay || hasClearKey) {
                            console.log('✅ DRM requirements detected from MPD manifest');
                            return true;
                        }
                        
                        console.log('ℹ️ No DRM requirements detected in MPD manifest');
                        return false;
                        
                    } catch (manifestError) {
                        console.warn('Could not analyze MPD manifest for DRM:', manifestError);
                        // If we can't analyze the manifest, assume no DRM to be safe
                        return false;
                    }
                }

                // For non-MPD URLs, assume no DRM
                return false;
                
            } catch (error) {
                console.warn('DRM detection failed:', error);
                return false;
            }
        }

        // Check if URL is YouTube
        function isYouTubeUrl(url) {
            if (!url) return false;
            return url.includes('youtube.com') || url.includes('youtu.be');
        }

        // Check if URL is Vidsrc
        function isVidsrcUrl(url) {
            if (!url) return false;
            return url.includes('vidsrc.net/embed') || url.includes('vidsrc.me/embed') || url.includes('vidsrc.pro/embed') || url.includes('vidsrc.win/embed') || 
                   url.includes('vidsrc.me') || url.includes('vidsrc.to') || url.includes('vidsrc.xyz') || url.includes('vidsrc.win') ||
                   url.includes('godriveplayer') || url.includes('vidlink.pro') || url.includes('2embed.cc') ||
                   url.includes('embed.su') || url.includes('autoembed.cc') || url.includes('vidfast.pro');
        }

        // Check if URL is Vidjoy
        function isVidjoyUrl(url) {
            if (!url) return false;
            return url.includes('vidjoy.pro/embed');
        }

        // Check if URL is VidSrc.me (New embeddable source)
        function isVidsrcMeUrl(url) {
            if (!url) return false;
            return url.includes('vidsrc.me');
        }

        // Check if URL is VidSrc.to (New embeddable source)
        function isVidsrcToUrl(url) {
            if (!url) return false;
            return url.includes('vidsrc.to');
        }

        // Check if URL is VidSrc.xyz (New embeddable source)
        function isVidsrcXyzUrl(url) {
            if (!url) return false;
            return url.includes('vidsrc.xyz');
        }

        // Check if URL is GoDrivePlayer (New embeddable source)
        function isGoDrivePlayerUrl(url) {
            if (!url) return false;
            return url.includes('godriveplayer');
        }

        // Check if URL is VidLink.pro (New embeddable source)
        function isVidLinkProUrl(url) {
            if (!url) return false;
            return url.includes('vidlink.pro');
        }

        // Check if URL is 2embed.cc (New embeddable source)
        function is2EmbedUrl(url) {
            if (!url) return false;
            return url.includes('2embed.cc');
        }

        // Check if URL is Embed.su (New embeddable source)
        function isEmbedSuUrl(url) {
            if (!url) return false;
            return url.includes('embed.su');
        }

        // Check if URL is AutoEmbed.cc (New embeddable source)
        function isAutoEmbedUrl(url) {
            if (!url) return false;
            return url.includes('autoembed.cc');
        }

        // Check if URL is Videasy (embedded source)
        function isVideasyUrl(url) {
            if (!url) return false;
            return url.includes('videasy');
        }
        
        // Check if URL is a Vidfast that needs /embed/ path
        function isVidfastEmbedUrl(url) {
            if (!url) return false;
            return url.includes('vidfast.net') || url.includes('vidfast.to');
        }
        
        // Check if URL is Vidplus
        function isVidplusUrl(url) {
            if (!url) return false;
            return url.includes('vidplus.to');
        }
        
        // Create universal server selector for both Plyr and embedded sources
        function createUniversalServerSelector(servers, currentServer = null) {
            if (!servers || servers.length <= 1) return; // No need for selector if only one server
            
            // Remove existing server selector if it exists
            const existingSelector = document.getElementById('universal-server-selector');
            if (existingSelector) {
                existingSelector.remove();
            }
            
            // Create server selector container
            const selectorContainer = document.createElement('div');
            selectorContainer.id = 'universal-server-selector';
            selectorContainer.style.cssText = `
                position: absolute;
                top: 10px;
                right: 10px;
                z-index: 1000;
                background: rgba(0, 0, 0, 0.9);
                border-radius: 12px;
                padding: 15px;
                min-width: 250px;
                max-width: 90vw;
                backdrop-filter: blur(15px);
                border: 1px solid rgba(255, 255, 255, 0.2);
                box-shadow: 0 8px 32px rgba(0, 0, 0, 0.6);
                max-height: 400px;
                overflow-y: auto;
            `;
            // Hide by default; show via the indicator button
            selectorContainer.style.display = 'none';
            // Compact sizing on small screens
            if (window.innerWidth <= 768) {
                selectorContainer.style.minWidth = '180px';
                selectorContainer.style.maxWidth = '85vw';
                selectorContainer.style.maxHeight = '50vh';
                selectorContainer.style.padding = '12px';
                selectorContainer.style.top = '8px';
                selectorContainer.style.right = '8px';
            }
            
            // Create title
            const title = document.createElement('div');
            title.style.cssText = `
                color: white;
                font-size: 16px;
                font-weight: bold;
                margin-bottom: 15px;
                text-align: center;
                padding-bottom: 10px;
                border-bottom: 1px solid rgba(255, 255, 255, 0.2);
            `;
            title.textContent = '🌐 Server Selection';
            selectorContainer.appendChild(title);
            
            // Create server list
            const serverList = document.createElement('div');
            serverList.style.cssText = `
                display: flex;
                flex-direction: column;
                gap: 8px;
            `;
            
            servers.forEach((server, index) => {
                const serverItem = document.createElement('div');
                serverItem.style.cssText = `
                    padding: 12px 16px;
                    background: rgba(255, 255, 255, 0.1);
                    border-radius: 8px;
                    cursor: pointer;
                    transition: all 0.3s ease;
                    color: white;
                    font-size: 14px;
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    border: 2px solid transparent;
                `;
                
                // Highlight current server
                const isCurrentServer = currentServer && (
                    (currentServer.url && server.url === currentServer.url) ||
                    (currentServer.src && server.url === currentServer.src)
                );
                
                if (isCurrentServer) {
                    serverItem.style.background = 'rgba(59, 130, 246, 0.6)';
                    serverItem.style.border = '2px solid rgba(59, 130, 246, 0.8)';
                }
                
                // Server name with quality indicator
                const serverName = document.createElement('span');
                serverName.style.cssText = `
                    display: flex;
                    align-items: center;
                    gap: 8px;
                `;
                
                // Add quality icon based on server name
                let qualityIcon = '📺';
                if (server.name) {
                    if (server.name.includes('1080p') || server.name.includes('1080')) {
                        qualityIcon = '🎬';
                    } else if (server.name.includes('720p') || server.name.includes('720')) {
                        qualityIcon = '📺';
                    } else if (server.name.includes('480p') || server.name.includes('480')) {
                        qualityIcon = '📱';
                    } else if (server.name.includes('360p') || server.name.includes('360')) {
                        qualityIcon = '📱';
                    }
                }
                
                serverName.innerHTML = `${qualityIcon} ${server.name || `Server ${index + 1}`}`;
                serverItem.appendChild(serverName);
                
                // Server status indicator
                const statusIndicator = document.createElement('span');
                statusIndicator.style.cssText = `
                    width: 10px;
                    height: 10px;
                    border-radius: 50%;
                    background: #10b981;
                    margin-left: 12px;
                    box-shadow: 0 0 8px rgba(16, 185, 129, 0.6);
                `;
                serverItem.appendChild(statusIndicator);
                
                // Add hover effects
                serverItem.addEventListener('mouseenter', () => {
                    if (!isCurrentServer) {
                        serverItem.style.background = 'rgba(255, 255, 255, 0.2)';
                        serverItem.style.transform = 'translateX(3px)';
                    }
                });
                
                serverItem.addEventListener('mouseleave', () => {
                    if (isCurrentServer) {
                        serverItem.style.background = 'rgba(59, 130, 246, 0.6)';
                    } else {
                        serverItem.style.background = 'rgba(255, 255, 255, 0.1)';
                    }
                    serverItem.style.transform = 'translateX(0)';
                });
                
                // Add click handler to switch servers
                serverItem.addEventListener('click', () => {
                    if (!isCurrentServer) {
                        switchToServer(server, servers);
                        
                        // Update visual selection
                        document.querySelectorAll('#universal-server-selector .server-item').forEach(item => {
                            item.style.background = 'rgba(255, 255, 255, 0.1)';
                            item.style.border = '2px solid transparent';
                        });
                        serverItem.style.background = 'rgba(59, 130, 246, 0.6)';
                        serverItem.style.border = '2px solid rgba(59, 130, 246, 0.8)';
                        
                        // Show feedback
                        showServerSwitchFeedback(server.name || `Server ${index + 1}`);
                    }
                });
                
                // Add class for easy selection
                serverItem.className = 'server-item';
                serverList.appendChild(serverItem);
            });
            
            selectorContainer.appendChild(serverList);
            
            // Add close button
            const closeButton = document.createElement('button');
            closeButton.style.cssText = `
                position: absolute;
                top: 8px;
                right: 8px;
                background: rgba(255, 255, 255, 0.1);
                border: none;
                color: white;
                font-size: 18px;
                cursor: pointer;
                padding: 4px 8px;
                border-radius: 6px;
                transition: all 0.2s ease;
                width: 32px;
                height: 32px;
                display: flex;
                align-items: center;
                justify-content: center;
            `;
            closeButton.innerHTML = '×';
            closeButton.addEventListener('click', () => {
                selectorContainer.style.display = 'none';
            });
            closeButton.addEventListener('mouseenter', () => {
                closeButton.style.background = 'rgba(255, 255, 255, 0.2)';
                closeButton.style.transform = 'scale(1.1)';
            });
            closeButton.addEventListener('mouseleave', () => {
                closeButton.style.background = 'rgba(255, 255, 255, 0.1)';
                closeButton.style.transform = 'scale(1)';
            });
            selectorContainer.appendChild(closeButton);
            
            // Add to player container
            const playerContainer = document.querySelector('.player-container') || document.getElementById('player-container');
            if (playerContainer) {
                playerContainer.appendChild(selectorContainer);
                
                // Add a floating indicator button
                const indicatorButton = document.createElement('div');
                indicatorButton.id = 'server-selector-indicator';
                indicatorButton.style.cssText = `
                    position: absolute;
                    top: 10px;
                    left: 10px;
                    background: rgba(59, 130, 246, 0.9);
                    color: white;
                    padding: 8px 12px;
                    border-radius: 20px;
                    font-size: 12px;
                    cursor: pointer;
                    z-index: 999;
                    backdrop-filter: blur(10px);
                    border: 1px solid rgba(255, 255, 255, 0.3);
                    transition: all 0.3s ease;
                    opacity: 0.8;
                    font-weight: 500;
                    box-shadow: 0 4px 16px rgba(59, 130, 246, 0.4);
                `;
                indicatorButton.innerHTML = `🌐 ${servers.length} Servers`;
                indicatorButton.title = 'Click to show server selector';
                
                indicatorButton.addEventListener('click', () => {
                    selectorContainer.style.display = 'block';
                    selectorContainer.style.opacity = '1';
                });
                
                indicatorButton.addEventListener('mouseenter', () => {
                    indicatorButton.style.opacity = '1';
                    indicatorButton.style.transform = 'scale(1.05)';
                });
                
                indicatorButton.addEventListener('mouseleave', () => {
                    indicatorButton.style.opacity = '0.8';
                    indicatorButton.style.transform = 'scale(1)';
                });
                
                playerContainer.appendChild(indicatorButton);
            }
        }

        
        // Enhanced function to show server switch feedback
        function showServerSwitchFeedback(message, type = 'success') {
            const feedbackDiv = document.createElement('div');
            feedbackDiv.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                background: ${type === 'success' ? 'rgba(16, 185, 129, 0.9)' : 'rgba(239, 68, 68, 0.9)'};
                color: white;
                padding: 12px 20px;
                border-radius: 8px;
                font-size: 14px;
                font-weight: 500;
                z-index: 10000;
                backdrop-filter: blur(10px);
                border: 1px solid rgba(255, 255, 255, 0.2);
                box-shadow: 0 4px 16px rgba(0, 0, 0, 0.3);
                transform: translateX(100%);
                transition: transform 0.3s ease;
            `;
            feedbackDiv.textContent = message;
            
            document.body.appendChild(feedbackDiv);
            
            // Animate in
            setTimeout(() => {
                feedbackDiv.style.transform = 'translateX(0)';
            }, 100);
            
            // Remove after 3 seconds
            setTimeout(() => {
                feedbackDiv.style.transform = 'translateX(100%)';
                setTimeout(() => {
                    if (feedbackDiv.parentNode) {
                        feedbackDiv.parentNode.removeChild(feedbackDiv);
                    }
                }, 300);
            }, 3000);
        }

        // Create server selector for embedded sources when Plyr player is hidden
        function createEmbeddedServerSelector(servers) {
            if (!servers || servers.length <= 1) return; // No need for selector if only one server
            
            // Remove existing server selector if it exists
            const existingSelector = document.getElementById('embedded-server-selector');
            if (existingSelector) {
                existingSelector.remove();
            }
            
            // Create server selector container
            const selectorContainer = document.createElement('div');
            selectorContainer.id = 'embedded-server-selector';
            selectorContainer.style.cssText = `
                position: absolute;
                top: 10px;
                right: 10px;
                z-index: 1000;
                background: rgba(0, 0, 0, 0.8);
                border-radius: 8px;
                padding: 10px;
                min-width: 200px;
                backdrop-filter: blur(10px);
                border: 1px solid rgba(255, 255, 255, 0.1);
            `;
            
            // Create title
            const title = document.createElement('div');
            title.style.cssText = `
                color: white;
                font-size: 14px;
                font-weight: bold;
                margin-bottom: 8px;
                text-align: center;
            `;
            title.textContent = '🌐 Server Selection';
            selectorContainer.appendChild(title);
            
            // Add Back to Home button
            const backToHomeButton = document.createElement('button');
            backToHomeButton.innerHTML = '🏠 Back to Home';
            backToHomeButton.style.cssText = `
                background: rgba(239, 68, 68, 0.7);
                color: white;
                border: none;
                padding: 8px 16px;
                border-radius: 6px;
                cursor: pointer;
                margin-bottom: 12px;
                width: 100%;
                font-size: 13px;
                font-weight: 500;
                transition: all 0.2s ease;
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 6px;
            `;
            backToHomeButton.addEventListener('mouseenter', () => {
                backToHomeButton.style.background = 'rgba(239, 68, 68, 0.9)';
                backToHomeButton.style.transform = 'scale(1.02)';
            });
            backToHomeButton.addEventListener('mouseleave', () => {
                backToHomeButton.style.background = 'rgba(239, 68, 68, 0.7)';
                backToHomeButton.style.transform = 'scale(1)';
            });
            backToHomeButton.addEventListener('click', () => {
                // Hide the iframe
                if (iframe) {
                    iframe.style.display = 'none';
                    iframe.src = 'about:blank';
                }
                
                // Show the Plyr player again
                if (elements.player) {
                    elements.player.style.display = 'block';
                }
                
                // Remove the server selector
                selectorContainer.remove();
                
                // Remove the indicator button
                const indicatorButton = document.getElementById('server-selector-indicator');
                if (indicatorButton) {
                    indicatorButton.remove();
                }
                
                // Clear any embedded content loading messages
                if (elements.playerMessageArea && elements.playerMessageArea.textContent.includes('embedded')) {
                    elements.playerMessageArea.style.display = 'none';
                }
                
                // Show success message
                showServerSwitchFeedback('Returned to Home');
            });
            selectorContainer.appendChild(backToHomeButton);
            
            // Create server list
            const serverList = document.createElement('div');
            serverList.style.cssText = `
                max-height: 200px;
                overflow-y: auto;
            `;
            
            servers.forEach((server, index) => {
                const serverItem = document.createElement('div');
                serverItem.style.cssText = `
                    padding: 8px 12px;
                    margin: 4px 0;
                    background: rgba(255, 255, 255, 0.1);
                    border-radius: 6px;
                    cursor: pointer;
                    transition: all 0.2s ease;
                    color: white;
                    font-size: 13px;
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                `;
                
                // Highlight current server
                if (server.url === iframe?.src) {
                    serverItem.style.background = 'rgba(59, 130, 246, 0.6)';
                    serverItem.style.border = '1px solid rgba(59, 130, 246, 0.8)';
                }
                
                // Server name
                const serverName = document.createElement('span');
                serverName.textContent = server.name || `Server ${index + 1}`;
                serverItem.appendChild(serverName);
                
                // Server status indicator
                const statusIndicator = document.createElement('span');
                statusIndicator.style.cssText = `
                    width: 8px;
                    height: 8px;
                    border-radius: 50%;
                    background: #10b981;
                    margin-left: 8px;
                `;
                serverItem.appendChild(statusIndicator);
                
                // Add hover effects
                serverItem.addEventListener('mouseenter', () => {
                    serverItem.style.background = 'rgba(255, 255, 255, 0.2)';
                    serverItem.style.transform = 'translateX(2px)';
                });
                
                serverItem.addEventListener('mouseleave', () => {
                    if (server.url === iframe?.src) {
                        serverItem.style.background = 'rgba(59, 130, 246, 0.6)';
                    } else {
                        serverItem.style.background = 'rgba(255, 255, 255, 0.1)';
                    }
                    serverItem.style.transform = 'translateX(0)';
                });
                
                // Add click handler to switch servers
                serverItem.addEventListener('click', () => {
                    if (server.url !== iframe?.src) {
                        // Update iframe source
                        if (iframe) {
                            // Re-attach the onload handler for the new server
                            if (iframe._onloadHandler) {
                                iframe.onload = iframe._onloadHandler;
                            }
                            iframe.src = server.url;
                        }
                        
                        // Update visual selection
                        document.querySelectorAll('#embedded-server-selector .server-item').forEach(item => {
                            item.style.background = 'rgba(255, 255, 255, 0.1)';
                            item.style.border = 'none';
                        });
                        serverItem.style.background = 'rgba(59, 130, 246, 0.6)';
                        serverItem.style.border = '1px solid rgba(59, 130, 246, 0.8)';
                        
                        // Show feedback
                        showServerSwitchFeedback(server.name || `Server ${index + 1}`);
                    }
                });
                
                // Add class for easy selection
                serverItem.className = 'server-item';
                serverList.appendChild(serverItem);
            });
            
            selectorContainer.appendChild(serverList);
            
            // Add close button
            const closeButton = document.createElement('button');
            closeButton.style.cssText = `
                position: absolute;
                top: 5px;
                right: 5px;
                background: none;
                border: none;
                color: white;
                font-size: 16px;
                cursor: pointer;
                padding: 2px;
                border-radius: 4px;
                transition: background 0.2s ease;
            `;
            closeButton.innerHTML = '×';
            closeButton.addEventListener('click', () => {
                selectorContainer.remove();
            });
            closeButton.addEventListener('mouseenter', () => {
                closeButton.style.background = 'rgba(255, 255, 255, 0.2)';
            });
            closeButton.addEventListener('mouseleave', () => {
                closeButton.style.background = 'none';
            });
            selectorContainer.appendChild(closeButton);
            
                            // Add to player container
                const playerContainer = document.querySelector('.player-container') || document.getElementById('player-container');
                if (playerContainer) {
                    playerContainer.appendChild(selectorContainer);
                    
                    // Add a small floating indicator button
                const indicatorButton = document.createElement('div');
                indicatorButton.id = 'server-selector-indicator';
                indicatorButton.style.cssText = `
                    position: absolute;
                    top: 10px;
                    left: 10px;
                    background: rgba(59, 130, 246, 0.8);
                    color: white;
                    padding: 6px 10px;
                    border-radius: 20px;
                    font-size: 11px;
                    cursor: pointer;
                    z-index: 999;
                    backdrop-filter: blur(5px);
                    border: 1px solid rgba(255, 255, 255, 0.2);
                    transition: all 0.2s ease;
                    opacity: 0.7;
                `;
                indicatorButton.innerHTML = `🌐 ${servers.length} Servers`;
                indicatorButton.title = 'Click to show server selector (Ctrl+S)';
                
                indicatorButton.addEventListener('click', () => {
                    selectorContainer.style.opacity = '1';
                    selectorContainer.style.transform = 'scale(1)';
                    clearTimeout(hideTimeout);
                });
                
                indicatorButton.addEventListener('mouseenter', () => {
                    indicatorButton.style.opacity = '1';
                    indicatorButton.style.transform = 'scale(1.05)';
                });
                
                indicatorButton.addEventListener('mouseleave', () => {
                    indicatorButton.style.opacity = '0.7';
                    indicatorButton.style.transform = 'scale(1)';
                });
                
                playerContainer.appendChild(indicatorButton);
            }
            
            // Auto-hide after 5 seconds of inactivity
            let hideTimeout;
            const resetHideTimeout = () => {
                clearTimeout(hideTimeout);
                hideTimeout = setTimeout(() => {
                    selectorContainer.style.opacity = '0.3';
                    selectorContainer.style.transform = 'scale(0.95)';
                }, 5000);
            };
            
            selectorContainer.addEventListener('mouseenter', () => {
                selectorContainer.style.opacity = '1';
                selectorContainer.style.transform = 'scale(1)';
                clearTimeout(hideTimeout);
            });
            
            selectorContainer.addEventListener('mouseleave', resetHideTimeout);
            resetHideTimeout();
            
            // Add keyboard shortcut (S key) to toggle server selector
            const handleKeyPress = (event) => {
                if (event.key.toLowerCase() === 's' && event.ctrlKey) {
                    event.preventDefault();
                    if (selectorContainer.style.opacity === '0.3') {
                        selectorContainer.style.opacity = '1';
                        selectorContainer.style.transform = 'scale(1)';
                        clearTimeout(hideTimeout);
                    } else {
                        selectorContainer.style.opacity = '0.3';
                        selectorContainer.style.transform = 'scale(0.95)';
                    }
                }
            };
            
            document.addEventListener('keydown', handleKeyPress);
            
            // Store the event listener reference for cleanup
            selectorContainer._keyPressHandler = handleKeyPress;
        }

        // Hide embedded server selector
        function hideEmbeddedServerSelector() {
            const existingSelector = document.getElementById('embedded-server-selector');
            if (existingSelector) {
                // Remove keyboard event listener before removing element
                if (existingSelector._keyPressHandler) {
                    document.removeEventListener('keydown', existingSelector._keyPressHandler);
                }
                existingSelector.remove();
            }
            
            // Also remove the indicator button
            const indicatorButton = document.getElementById('server-selector-indicator');
            if (indicatorButton) {
                indicatorButton.remove();
            }
            
            // Clear any loading messages for embedded content
            if (elements.playerMessageArea && elements.playerMessageArea.textContent.includes('embedded')) {
                elements.playerMessageArea.style.display = 'none';
            }
        }

        // Show feedback when switching servers
        function showServerSwitchFeedback(serverName) {
            const feedback = document.createElement('div');
            feedback.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                background: rgba(59, 130, 246, 0.9);
                color: white;
                padding: 12px 20px;
                border-radius: 8px;
                font-size: 14px;
                z-index: 10000;
                animation: slideInRight 0.3s ease;
            `;
            feedback.textContent = `🔄 Switched to ${serverName}`;
            
            // Add CSS animation
            if (!document.getElementById('server-switch-animations')) {
                const style = document.createElement('style');
                style.id = 'server-switch-animations';
                style.textContent = `
                    @keyframes slideInRight {
                        from { transform: translateX(100%); opacity: 0; }
                        to { transform: translateX(0); opacity: 1; }
                    }
                `;
                document.head.appendChild(style);
            }
            
            document.body.appendChild(feedback);
            
            // Remove after 3 seconds
            setTimeout(() => {
                feedback.style.animation = 'slideOutRight 0.3s ease';
                setTimeout(() => feedback.remove(), 300);
            }, 3000);
        }

        // Check if URL is Mega.nz
        function isMegaNzUrl(url) {
            if (!url) return false;
            return url.includes('mega.nz/file');
        }

        // Check if URL is Google Drive
        function isGoogleDriveUrl(url) {
            if (!url) return false;
            return url.includes('drive.google.com') || url.includes('drive.usercontent.google.com');
        }

        // Extract YouTube ID
        function extractYouTubeId(url) {
            const regExp = /^.*(youtu.be\/|v\/|u\/\w\/|embed\/|watch\?v=|&v=)([^#&?]*).*/;
            const match = url.match(regExp);
            return (match && match[2].length === 11) ? match[2] : null;
        }

        function extractGoogleDriveId(url) {
            let id = null;
            if (url.includes('drive.google.com')) {
                const match = url.match(/file\/d\/([^/]+)/);
                if (match) {
                    id = match[1];
                }
            } else if (url.includes('drive.usercontent.google.com')) {
                const match = url.match(/id=([^&]+)/);
                if (match) {
                    id = match[1];
                }
            }
            return id;
        }
        
        // Enhanced player source update with DRM awareness
        async function updatePlayerSource(servers) {
            if (!playerInstance) return;

            if (servers && servers.length > 0) {
                // Use DRM-aware server selection
                const optimalServer = selectOptimalServer(servers);
                
                if (optimalServer) {
                    // Check for special URLs first
                    if (isVidsrcUrl(optimalServer.url) || isVidjoyUrl(optimalServer.url) || isVidsrcMeUrl(optimalServer.url) || isVidsrcToUrl(optimalServer.url) || isVidsrcXyzUrl(optimalServer.url) || isGoDrivePlayerUrl(optimalServer.url) || isVidLinkProUrl(optimalServer.url) || is2EmbedUrl(optimalServer.url) || isEmbedSuUrl(optimalServer.url) || isAutoEmbedUrl(optimalServer.url) || isVideasyUrl(optimalServer.url) || isMegaNzUrl(optimalServer.url) || isGoogleDriveUrl(optimalServer.url)) {
                        playUrl(optimalServer.url, servers);
                        return;
                    }
                    
                    // Handle MPD files with enhanced DRM support
                    if (optimalServer.url.includes('.mpd')) {
                        await handleMPDStream(optimalServer.url, servers);
                        return;
                    }
                    
                    // Handle regular video sources
                    const sources = servers.map(server => ({
                        src: server.url,
                        size: parseInt(server.name?.replace(/\D/g, '') || '0'),
                        type: server.url.includes('m3u8') ? 'application/x-mpegURL' : 'video/mp4'
                    }));
                    
                    playerInstance.source = {
                        type: 'video',
                        sources: sources,
                    };
                    
                    // Ensure stretch button persists after source change
                    addStretchButtonToPlayer();
                    
                    // Update in-page server dropdown for multiple servers
                    updateServerDropdown(servers, optimalServer);
                    
                    // Add error handling for automatic server switching
                    playerInstance.on('error', async (event) => {
                        console.log('Player error detected, trying next server...');
                        const currentIndex = servers.findIndex(s => s.url === optimalServer.url);
                        await tryNextServer(servers, currentIndex);
                    });
                }
            }
        }
        
        // Open season
        function openSeason(season) {
            currentSeason = season;

            // Update the episode selector
            elements.episodeSelector.innerHTML = '<option value="">Select Episode</option>';
            season.Episodes.forEach(ep => {
                const option = document.createElement('option');
                option.value = ep.Episode;
                option.textContent = `Episode ${ep.Episode}: ${ep.Title}`;
                elements.episodeSelector.appendChild(option);
            });

            // Show the episode selector
            elements.episodeSelectorContainer.style.display = 'block';

            // Play the first episode by default
            if (season.Episodes.length > 0) {
                const firstEpisode = season.Episodes[0];
                elements.episodeSelector.value = firstEpisode.Episode;
                playEpisode(firstEpisode, true);
            } else {
                // Season has no episodes, ensure buttons reflect this
                currentEpisode = null; // Clear currentEpisode
                updateNavigationButtonsState();
            }
        }
        
        // Play episode
        function playEpisode(episode, autoplay = false) {
            currentEpisode = episode;

            // Update the viewer title to include episode info
            elements.viewerTitle.textContent = `${currentSeries.Title} - ${episode.Title}`;

            // Update the description to the episode description
            elements.viewerDescription.textContent = episode.Description || currentSeries.Description;

            // Update quality selector
            if (episode.Servers) {
                updatePlayerSource(episode.Servers);
            }

            // Ensure the episode selector reflects the current episode
            if (elements.episodeSelector && episode.Episode !== undefined) {
                elements.episodeSelector.value = episode.Episode;
            }

            // Update history state for episode navigation
            if (currentSeries && currentSeason) {
                const episodeState = {
                    page: 'viewer',
                    contentId: currentSeries.Title,
                    season: currentSeason.Season,
                    episode: episode.Episode
                };
                history.pushState(episodeState, `Episode ${episode.Episode}`, `#episode-${episode.Episode}`);
            }

            updateNavigationButtonsState(); // Update buttons after episode changes

            if (autoplay) {
                // Enhanced autoplay with proper timing
                setTimeout(() => {
                    if (playerInstance && typeof playerInstance.play === 'function') {
                        enhancedAutoplay(elements.player, playerInstance);
                    }
                }, 1000); // Increased delay for better loading
            }
        }
        
        // Close viewer (UI and state update part)
        function closeViewerInternalLogic() {
            // Show browse sections
            document.querySelector('.carousel').style.display = 'block';
            document.querySelector('.filters-section').style.display = 'block';
            document.querySelector('.content-container').style.display = 'block';

            // Hide viewer
            elements.viewerPage.style.display = 'none';

            // Stop all players
            if (playerInstance) {
                playerInstance.pause();
                playerInstance.stop();
            }

            // Stop Shaka Player if it exists
            if (window.currentShakaPlayer) {
                try {
                    window.currentShakaPlayer.destroy();
                    window.currentShakaPlayer = null;
                } catch (error) {
                    console.log('Error destroying Shaka Player:', error);
                }
            }

            // Reset dash.js player if it exists
            if (dashPlayer) {
                dashPlayer.reset();
                dashPlayer = null;
            }

            // Reset player state
            if (playerInstance && playerInstance.source) {
                playerInstance.source = null;
            }

            // Explicitly find and remove any Vidsrc/external iframe
            const playerContainer = document.querySelector('.player-container');
            if (playerContainer) {
                const externalIframe = playerContainer.querySelector('iframe.external-content-iframe');
                if (externalIframe) {
                    externalIframe.src = 'about:blank'; // Stop loading/playing
                    externalIframe.remove(); // Remove from DOM
                }
            }
            // Ensure Plyr player is visible again if it was hidden for an iframe
            elements.player.style.display = 'block';


            // Move season/episode selectors back to videoDetailsContainer and hide them
            if (elements.videoDetailsContainer) { // Ensure container exists
                elements.videoDetailsContainer.appendChild(elements.episodeSelectorContainer);
                elements.videoDetailsContainer.appendChild(elements.seasonSelector);
            }
            elements.episodeSelectorContainer.style.display = 'none';
            elements.seasonSelector.style.display = 'none';

            // Reset viewer state
            currentEpisode = null;
            currentSeason = null;
            currentSeries = null;
            currentContentInfo = {}; // Reset content info

            if (elements.prevEpisodeBtn && elements.nextEpisodeBtn) { 
                elements.prevEpisodeBtn.style.display = 'none';
                elements.nextEpisodeBtn.style.display = 'none';
            }

            // Hide player message area
            if (elements.playerMessageArea) {
                elements.playerMessageArea.style.display = 'none';
                elements.playerMessageArea.textContent = '';
            }

            window.scrollTo(0, 0); // Scroll to top when returning to browse
            elements.backButton.focus(); // Return focus to the back button or another main page element
        }

        // Close viewer (called by back button)
        function closeViewer() {
            // This button press now only handles the UI change directly.
            // Browser history (e.g. physical back button) is handled by popstate.
            closeViewerInternalLogic();
        }
        
        // Setup mobile back button support
        function setupMobileBackButton() {
            // Detect mobile device and show appropriate back button text
            const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
            if (isMobile) {
                const backText = document.querySelector('.back-text');
                const mobileHint = document.querySelector('.mobile-back-hint');
                if (backText && mobileHint) {
                    backText.style.display = 'none';
                    mobileHint.style.display = 'inline-block';
                }
            }
            // Handle browser back button (mobile and desktop)
            window.addEventListener('popstate', function(event) {
                if (elements.viewerPage.style.display !== 'none') {
                    console.log('Browser back button pressed, handling navigation');
                    
                    if (event.state && event.state.page === 'browse') {
                        // Going back to browse page
                        closeViewerInternalLogic();
                    } else if (event.state && event.state.page === 'viewer' && event.state.episode) {
                        // Going back to previous episode
                        handleEpisodeBackNavigation(event.state);
                    } else {
                        // Default: close viewer
                        closeViewerInternalLogic();
                    }
                }
            });

            // Handle mobile back button (Android)
            if (navigator.userAgent.includes('Android')) {
                document.addEventListener('backbutton', function() {
                    if (elements.viewerPage.style.display !== 'none') {
                        console.log('Mobile back button pressed, handling navigation');
                        
                        // Check if we're in an episode and can go back
                        if (currentEpisode && currentSeason && currentSeries) {
                            const currentEpisodeIndex = currentSeason.Episodes.findIndex(ep => ep.Episode === currentEpisode.Episode);
                            if (currentEpisodeIndex > 0) {
                                // Go to previous episode
                                const previousEpisode = currentSeason.Episodes[currentEpisodeIndex - 1];
                                playEpisode(previousEpisode, true);
                                return;
                            }
                        }
                        
                        // If no previous episode or at first episode, close viewer
                        closeViewerInternalLogic();
                    }
                });
            }

            // Handle ESC key for desktop
            document.addEventListener('keydown', function(event) {
                if (event.key === 'Escape' && elements.viewerPage.style.display !== 'none') {
                    console.log('ESC key pressed, closing viewer');
                    closeViewerInternalLogic();
                }
            });
        }

        // Handle episode back navigation
        function handleEpisodeBackNavigation(state) {
            if (state.season && state.episode && currentSeason) {
                const targetEpisode = currentSeason.Episodes.find(ep => ep.Episode === state.episode);
                if (targetEpisode) {
                    playEpisode(targetEpisode, true);
                    return;
                }
            }
            // If episode navigation fails, close viewer
            closeViewerInternalLogic();
        }

        // Setup event listeners
        function setupEventListeners() {
            // Refresh button
            const refreshBtn = document.getElementById('refresh-btn');
            if (refreshBtn) {
                refreshBtn.addEventListener('click', async () => {
                    if (!confirm("Are you sure you want to clear the local cache and refresh all data from the server? This may take a few minutes.")) {
                        return;
                    }
                    
                    try {
                        console.log("Clearing cache and reloading...");
                        const db = await dbUtil.open();
                        await dbUtil.clear(db);
                        db.close();

                        const notificationBar = document.getElementById('notification-bar');
                        const notificationMessage = document.getElementById('notification-message');
                        notificationMessage.textContent = "Cache cleared successfully! Reloading the latest data from the server...";
                        notificationBar.style.display = 'flex';
                        // Adjust header position after the notification bar is rendered
                        setTimeout(() => {
                            const header = document.querySelector('header');
                            if (notificationBar.offsetHeight > 0) {
                                header.style.top = `${notificationBar.offsetHeight}px`;
                            }
                        }, 100);

                        setTimeout(() => {
                            window.location.reload();
                        }, 2000);

                    } catch (error) {
                        console.error("Failed to clear cache:", error);
                        alert("There was an error clearing the cache. Please try clearing your browser's site data manually.");
                    }
                });
            }

            // Theme toggle
            elements.themeToggle.addEventListener('click', toggleTheme);

            // View toggle
            elements.viewToggleBtn.addEventListener('click', () => {
                const icon = elements.viewToggleBtn.querySelector('i');
                if (currentView === 'grid') {
                    currentView = 'list';
                    icon.classList.remove('fa-th');
                    icon.classList.add('fa-list');
                } else {
                    currentView = 'grid';
                    icon.classList.remove('fa-list');
                    icon.classList.add('fa-th');
                }
                renderCurrentView();
                setupLazyLoading();
            });

            // Filter changes
            const navItems = document.querySelectorAll('.nav-item');
            navItems.forEach(item => {
                item.addEventListener('click', async (e) => {
                    e.preventDefault();
                    const category = item.dataset.category;

                    if (elements.viewerPage.style.display === 'block') {
                        closeViewerInternalLogic();
                        history.replaceState({ page: 'browse' }, 'Browse Content', window.location.pathname + window.location.search);
                    }

                    await renderContent(category);

                    // Update active class
                    navItems.forEach(nav => nav.classList.remove('active'));
                    item.classList.add('active');
                });
            });

            elements.genreFilter.addEventListener('change', () => {
                const activeNavItem = document.querySelector('.nav-item.active');
                const category = activeNavItem ? activeNavItem.dataset.category : 'all';
                renderContent(category);
            });

            elements.countryFilter.addEventListener('change', () => {
                const activeNavItem = document.querySelector('.nav-item.active');
                const category = activeNavItem ? activeNavItem.dataset.category : 'all';
                renderContent(category);
            });

            elements.yearFilter.addEventListener('change', () => {
                const activeNavItem = document.querySelector('.nav-item.active');
                const category = activeNavItem ? activeNavItem.dataset.category : 'all';
                renderContent(category);
            });

            elements.sortFilter.addEventListener('change', () => {
                const activeNavItem = document.querySelector('.nav-item.active');
                const category = activeNavItem ? activeNavItem.dataset.category : 'all';
                renderContent(category);
            });

            // Search functionality
            elements.searchInput.addEventListener('input', debounce(handleSearch, 300));

            // Close search button
            elements.closeSearchBtn.addEventListener('click', function() {
                elements.searchInput.value = '';
                elements.searchResults.style.display = 'none';
                elements.searchInput.setAttribute('aria-expanded', 'false');
                if (elements.searchContainer) {
                    elements.searchContainer.classList.remove('show');
                }
            });

            // Carousel controls
            elements.carouselPrev.addEventListener('click', () => {
                currentCarouselIndex = (currentCarouselIndex - 1 + elements.carouselInner.children.length) %
                    elements.carouselInner.children.length;
                updateCarousel();
            });

            elements.carouselNext.addEventListener('click', () => {
                currentCarouselIndex = (currentCarouselIndex + 1) % elements.carouselInner.children.length;
                updateCarousel();
            });

            // Mobile search button
            elements.mobileSearchBtn.addEventListener('click', () => {
                elements.searchContainer.classList.toggle('show');
                if (elements.searchContainer.classList.contains('show')) {
                    elements.searchInput.focus();
                } else {
                    elements.searchResults.style.display = 'none'; // Hide results when toggling off
                    elements.searchInput.setAttribute('aria-expanded', 'false');
                }
            });

            // Close search when clicking outside (primarily for mobile)
            document.addEventListener('click', (event) => {
                const isMobile = window.innerWidth <= 768;
                if (isMobile && elements.searchContainer.classList.contains('show')) {
                    if (!elements.searchContainer.contains(event.target) &&
                        !elements.mobileSearchBtn.contains(event.target)) {
                        elements.searchContainer.classList.remove('show');
                        elements.searchResults.style.display = 'none'; // Also hide results
                        elements.searchInput.setAttribute('aria-expanded', 'false');
                    }
                }
            });

            // Header scroll effect
            window.addEventListener('scroll', () => {
                if (window.scrollY > 50) {
                    elements.header.classList.add('scrolled');
                } else {
                    elements.header.classList.remove('scrolled');
                }
            });

            // Back button in viewer
            elements.backButton.addEventListener('click', function() {
                // Directly close the viewer UI to ensure predictability for scripts
                closeViewerInternalLogic();
                // Update history state to reflect the change
                history.pushState({ page: 'browse' }, 'Browse Content', window.location.pathname + window.location.search);
            });

            // Enhanced mobile back button support
            if ('ontouchstart' in window) {
                // Touch device detected
                elements.backButton.addEventListener('touchstart', function(e) {
                    this.style.transform = 'scale(0.95)';
                });

                elements.backButton.addEventListener('touchend', function(e) {
                    this.style.transform = 'scale(1)';
                    // Small delay to ensure touch feedback is visible
                    setTimeout(() => {
                        history.back();
                    }, 100);
                });

                // Prevent default touch behavior to avoid conflicts
                elements.backButton.addEventListener('touchmove', function(e) {
                    e.preventDefault();
                });
            }

            // Episode selector change
            elements.episodeSelector.addEventListener('change', function() {
                const episodeNum = parseInt(this.value);
                if (!currentSeason) return;

                const episode = currentSeason.Episodes.find(ep => ep.Episode === episodeNum);
                if (episode) {
                    playEpisode(episode);
                }
            });

            // History API handling
            window.addEventListener('popstate', function(event) {
                const state = event.state;
                // console.log("Popstate event:", state, "Current hash:", window.location.hash);

                if (elements.viewerPage.style.display === 'block') { // If viewer is currently open
                    if (!state || state.page !== 'viewer') { // And we've navigated to a non-viewer state (e.g. back to 'browse')
                        // console.log("Popstate: Viewer is open, but current state is not 'viewer'. Closing viewer UI.");
                        closeViewerInternalLogic();
                        if (!state || state.page === 'browse') { // Clean up hash if back to browse state
                             history.replaceState({ page: 'browse' }, 'Browse Content', window.location.pathname + window.location.search);
                        }
                    }
                } else if (state && state.page === 'viewer' && window.location.hash === '#viewer') { // If viewer is NOT open, but state/hash indicates it should be (e.g. user pressed forward)
                    // console.log("Popstate: Viewer is not open, but state is 'viewer'. Re-opening viewer.");
                    const contentToOpen = findContentById(state.contentId);
                    if (contentToOpen) {
                        openViewer(contentToOpen);
                    } else { // Fallback if content not found
                        history.replaceState({ page: 'browse' }, 'Browse Content', window.location.pathname + window.location.search);
                        // closeViewerInternalLogic(); // Not needed as viewer is already closed
                    }
                }
            });

            // Episode navigation buttons
            if (elements.prevEpisodeBtn) {
                elements.prevEpisodeBtn.addEventListener('click', handlePrevEpisode);
            }
            if (elements.nextEpisodeBtn) {
                elements.nextEpisodeBtn.addEventListener('click', handleNextEpisode);
            }

            // Infinite scroll
            window.addEventListener('scroll', () => {
                if (isFetching) return;

                const scrollPosition = window.innerHeight + window.scrollY;
                const documentHeight = document.documentElement.scrollHeight;

                if (scrollPosition >= documentHeight - LAZY_LOAD_THRESHOLD) {
                    if (currentPage < totalPages) {
                        loadMoreContent();
                    }
                }
            });

            // Hamburger menu toggle
            elements.hamburgerBtn.addEventListener('click', () => {
                elements.mobileFiltersMenu.classList.toggle('show');
            });

            // Stretch button
            elements.stretchBtn.addEventListener('click', () => {
                toggleStretch();
            });
        }
        
        // Function to create and add the stretch button to Plyr controls
        function addStretchButtonToPlayer() {
            if (!playerInstance || !playerInstance.elements || !playerInstance.elements.controls) {
                return;
            }

            const controlsContainer = playerInstance.elements.controls;
            const settingsButton = controlsContainer.querySelector('button[data-plyr="settings"]');

            // Reuse existing button if it already exists to avoid duplicates
            let stretchButton = controlsContainer.querySelector('button[aria-label="Stretch"]');
            if (!stretchButton) {
                stretchButton = document.createElement('button');
                stretchButton.type = 'button';
                stretchButton.className = 'plyr__controls__item plyr__control';
                stretchButton.innerHTML = '<i class="fas fa-expand-arrows-alt"></i>';
                stretchButton.setAttribute('aria-label', 'Stretch');

                // Add event listener
                stretchButton.addEventListener('click', () => {
                    toggleStretch();
                    stretchButton.classList.toggle('active');
                });

                // Insert before the settings button
                if (settingsButton) {
                    settingsButton.parentNode.insertBefore(stretchButton, settingsButton);
                } else {
                    controlsContainer.appendChild(stretchButton);
                }
            }

            // Sync visual state and video fit
            const videoElement = playerInstance.elements.container.querySelector('video');
            if (videoElement) {
                videoElement.style.objectFit = isStretched ? 'fill' : 'contain';
            }
            if (isStretched) {
                stretchButton.classList.add('active');
            } else {
                stretchButton.classList.remove('active');
            }
        }
        
        // Toggle theme
        function toggleTheme() {
            document.body.classList.toggle('light-theme');
            const icon = elements.themeToggle.querySelector('i');
            if (document.body.classList.contains('light-theme')) {
                icon.classList.remove('fa-moon');
                icon.classList.add('fa-sun');
                document.body.style.backgroundColor = '#f0f0f0';
                document.body.style.color = '#333';
            } else {
                icon.classList.remove('fa-sun');
                icon.classList.add('fa-moon');
                document.body.style.backgroundColor = '#0f0f0f';
                document.body.style.color = '#f5f5f5';
            }
        }

        // Debounce function for search
        function debounce(func, wait) {
  let timeout;
  return function(...args) {
    const later = () => {
      clearTimeout(timeout);
      func.apply(this, args);
    };
    clearTimeout(timeout);
    timeout = setTimeout(later, wait);
  };
}


        // Handle search
        function handleSearch() {
            const query = elements.searchInput.value.toLowerCase();
            elements.searchResults.innerHTML = '';

            // Collapse listbox by default until we decide to show something
            elements.searchInput.setAttribute('aria-expanded', 'false');

            // Require minimal input length
            if (query.length < 2) {
                elements.searchResults.style.display = 'none';
                return;
            }

            // Determine if data is available for searching
            const dataLoaded = (cineData && cineData.Categories && cineData.Categories.length > 0) ||
                               (Array.isArray(cachedContent) && cachedContent.length > 0);

            if (!dataLoaded) {
                const message = document.createElement('div');
                message.className = 'search-message';
                message.setAttribute('role', 'status');
                message.textContent = 'Search unavailable. Content is still loading.';
                elements.searchResults.appendChild(message);
                elements.searchResults.style.display = 'block';
                elements.searchInput.setAttribute('aria-expanded', 'true');
                return;
            }

            const results = [];

            // Search through cached content
            cachedContent.forEach(item => {
                if (item.Title.toLowerCase().includes(query)) {
                    results.push({
                        title: item.Title,
                        type: item.type === 'movie' ? 'Movie' :
                              item.type === 'series' ? 'TV Series' : 'Live TV',
                        thumbnail: item.Thumbnail || item.Poster,
                        year: item.Year || ''
                    });
                }
            });

            if (results.length > 0) {
                results.slice(0, 5).forEach(result => {
                    const resultItem = document.createElement('div');
                    resultItem.className = 'search-result-item';
                    resultItem.setAttribute('role', 'option');
                    resultItem.setAttribute('aria-selected', 'false');
                    resultItem.innerHTML = `
                        <img src="${result.thumbnail}" alt="${result.title}" onerror="this.onerror=null; this.src='${PLACEHOLDER_IMAGE_URL}';">
                        <div class="search-result-info">
                            <h4>${result.title}</h4>
                            <p>${result.type} • ${result.year || ''}</p>
                        </div>
                    `;
                    resultItem.addEventListener('click', () => {
                        // Find the content and open viewer
                        const found = cachedContent.find(item => item.Title === result.title);
                        if (found) {
                            openViewer(found);
                        }
                        elements.searchResults.style.display = 'none';
                        elements.searchInput.setAttribute('aria-expanded', 'false');
                    });
                    elements.searchResults.appendChild(resultItem);
                });
                elements.searchResults.style.display = 'block';
                elements.searchInput.setAttribute('aria-expanded', 'true');
            } else {
                const message = document.createElement('div');
                message.className = 'search-message';
                message.setAttribute('role', 'status');
                message.textContent = `No results for "${elements.searchInput.value}"`;
                elements.searchResults.appendChild(message);
                elements.searchResults.style.display = 'block';
                elements.searchInput.setAttribute('aria-expanded', 'true');
            }
        }

        // Update carousel position
        function updateCarousel() {
            elements.carouselInner.style.transform = `translateX(-${currentCarouselIndex * 100}%)`;

            // Update indicators
            document.querySelectorAll('.indicator').forEach((indicator, index) => {
                if (index === currentCarouselIndex) {
                    indicator.classList.add('active');
                } else {
                    indicator.classList.remove('active');
                }
            });
        }

        // --- Parental Control Logic ---

        const ALL_RATINGS = {
            "Movies": ["G", "PG", "PG-13", "R", "NC-17"],
            "TV Shows": ["TV-Y", "TV-Y7", "TV-G", "TV-PG", "TV-14", "TV-MA"],
            "Philippines": ["SPG", "R-13", "R-16", "R-18"]
        };

        function setupParentalControls() {
            loadParentalControls();
            generatePinPad(elements.pinPad, handlePinPadClick);
            generatePinPad(elements.pinPadInput, handlePinEntryPinPadClick);
            generateRatingsCheckboxes();
            attachParentalEventListeners();
            updateParentalControlsUI();
        }

        function loadParentalControls() {
            const savedSettings = JSON.parse(localStorage.getItem('parentalControls'));
            if (savedSettings) {
                parentalControls = savedSettings;
                if (!Array.isArray(parentalControls.allowedRatings)) {
                    parentalControls.allowedRatings = [...ALL_RATINGS.Movies, ...ALL_RATINGS["TV Shows"], ...ALL_RATINGS.Philippines];
                }
            } else {
                // Default settings if nothing is saved
                parentalControls.allowedRatings = [...ALL_RATINGS.Movies, ...ALL_RATINGS["TV Shows"], ...ALL_RATINGS.Philippines];
            }
        }

        function saveParentalControls() {
            localStorage.setItem('parentalControls', JSON.stringify(parentalControls));
            updateParentalControlsUI();
        }

        function generatePinPad(container, handler) {
            container.innerHTML = '';
            const buttons = [1, 2, 3, 4, 5, 6, 7, 8, 9, 'backspace', 0, ''];
            buttons.forEach(val => {
                const btn = document.createElement('button');
                btn.className = 'pin-btn';
                if (typeof val === 'number') {
                    btn.textContent = val;
                    btn.addEventListener('click', () => handler(val));
                } else if (val === 'backspace') {
                    btn.innerHTML = '<i class="fas fa-backspace"></i>';
                    btn.classList.add('backspace');
                    btn.addEventListener('click', () => handler('backspace'));
                } else {
                    btn.style.visibility = 'hidden';
                }
                container.appendChild(btn);
            });
        }
        
        function generateRatingsCheckboxes() {
            const container = elements.ratingsCheckboxContainer;
            container.innerHTML = '';
            for (const category in ALL_RATINGS) {
                const title = document.createElement('h4');
                title.className = 'rating-category-title';
                title.textContent = category;
                container.appendChild(title);

                ALL_RATINGS[category].forEach(rating => {
                    const label = document.createElement('label');
                    label.className = 'rating-checkbox-label';
                    label.textContent = rating;
                    
                    const input = document.createElement('input');
                    input.type = 'checkbox';
                    input.value = rating;
                    input.checked = parentalControls.allowedRatings.includes(rating);
                    
                    const span = document.createElement('span');
                    span.className = 'checkmark';
                    
                    label.appendChild(input);
                    label.appendChild(span);
                    container.appendChild(label);
                });
            }
        }

        function updatePinDisplay(displayElement, pin) {
            const dots = displayElement.querySelectorAll('.pin-dot');
            dots.forEach((dot, index) => {
                if (index < pin.length) {
                    dot.classList.add('filled');
                } else {
                    dot.classList.remove('filled');
                }
            });
        }

        function updateParentalControlsUI() {
            elements.unratedContentToggle.checked = parentalControls.allowUnrated;
            if (parentalControls.pin) {
                elements.pinStatusText.textContent = "PIN is set. To change it, reset it first.";
            } else {
                elements.pinStatusText.textContent = "Enter a new 4-digit PIN.";
            }
            const allRatings = [...ALL_RATINGS.Movies, ...ALL_RATINGS["TV Shows"], ...ALL_RATINGS.Philippines];
            if (parentalControls.allowedRatings.length >= allRatings.length) {
                elements.allowedRatingsDisplay.textContent = 'All ratings allowed';
            } else if (parentalControls.allowedRatings.length === 0) {
                elements.allowedRatingsDisplay.textContent = 'No ratings allowed';
            } else {
                elements.allowedRatingsDisplay.textContent = parentalControls.allowedRatings.join(', ');
            }
        }

        function showModal(modalElement) {
            modalElement.style.display = 'flex';
        }

        function hideModal(modalElement) {
            modalElement.style.display = 'none';
        }

        function openParentalControls() {
            if (parentalControls.pin) {
                elements.pinEntryTitle.textContent = "Enter PIN to Open Settings";
                showModal(elements.pinEntryModal);
                pinEntryCallback = () => {
                    hideModal(elements.pinEntryModal);
                    showModal(elements.parentalControlsModal);
                };
            } else {
                showModal(elements.parentalControlsModal);
            }
        }

        function handlePinPadClick(value) {
            if (parentalControls.pin) {
                elements.pinStatusText.textContent = "PIN is already set. Reset it to change.";
                return;
            }

            if (value === 'backspace') {
                currentPinInput = currentPinInput.slice(0, -1);
            } else if (currentPinInput.length < 4) {
                currentPinInput += value;
            }
            
            updatePinDisplay(elements.pinDisplay, currentPinInput);

            if (currentPinInput.length === 4) {
                if (!isSettingPin) {
                    isSettingPin = true;
                    tempPin = currentPinInput;
                    elements.pinStatusText.textContent = 'Confirm your new PIN.';
                    currentPinInput = '';
                    setTimeout(() => updatePinDisplay(elements.pinDisplay, ''), 200);
                } else {
                    if (currentPinInput === tempPin) {
                        parentalControls.pin = tempPin;
                        saveParentalControls();
                        elements.pinStatusText.textContent = 'PIN set successfully!';
                        isSettingPin = false;
                        tempPin = '';
                        currentPinInput = '';
                         setTimeout(() => {
                           hideModal(elements.parentalControlsModal);
                        }, 1500);
                    } else {
                        elements.pinStatusText.textContent = 'PINs do not match. Try again.';
                        isSettingPin = false;
                        tempPin = '';
                        currentPinInput = '';
                        setTimeout(() => {
                            updatePinDisplay(elements.pinDisplay, '');
                            elements.pinStatusText.textContent = 'Enter a new 4-digit PIN.';
                        }, 2000);
                    }
                }
            }
        }

        function handlePinEntryPinPadClick(value) {
            if (value === 'backspace') {
                currentPinInput = currentPinInput.slice(0, -1);
            } else if (currentPinInput.length < 4) {
                currentPinInput += value;
            }
            updatePinDisplay(elements.pinDisplayInput, currentPinInput);
        }
        
        function verifyPin() {
            if (currentPinInput === parentalControls.pin) {
                elements.pinStatusTextInput.textContent = 'PIN Correct!';
                setTimeout(() => {
                    hideModal(elements.pinEntryModal);
                    if (pinEntryCallback) {
                        pinEntryCallback();
                    }
                    resetPinEntry();
                }, 500);
            } else {
                elements.pinStatusTextInput.textContent = 'Incorrect PIN. Please try again.';
                currentPinInput = '';
                setTimeout(() => {
                    updatePinDisplay(elements.pinDisplayInput, '');
                }, 1000);
            }
        }
        
        function resetPinEntry() {
            currentPinInput = '';
            pinEntryCallback = null;
            updatePinDisplay(elements.pinDisplayInput, '');
            elements.pinStatusTextInput.textContent = '';
        }

        function handleSaveRatings() {
            const selectedRatings = [];
            const checkboxes = elements.ratingsCheckboxContainer.querySelectorAll('input[type="checkbox"]:checked');
            checkboxes.forEach(checkbox => {
                selectedRatings.push(checkbox.value);
            });
            parentalControls.allowedRatings = selectedRatings;
            saveParentalControls();
            hideModal(elements.ratingsSelectModal);
        }

        function attachParentalEventListeners() {
            elements.parentalControlsLink.addEventListener('click', (e) => {
                e.preventDefault();
                openParentalControls();
            });
            elements.closeParentalControlsModal.addEventListener('click', () => hideModal(elements.parentalControlsModal));
            
            elements.changeRatingsBtn.addEventListener('click', () => {
                // Refresh checkboxes before showing
                generateRatingsCheckboxes();
                showModal(elements.ratingsSelectModal);
            });

            elements.okRatingsBtn.addEventListener('click', handleSaveRatings);
            elements.cancelRatingsBtn.addEventListener('click', () => hideModal(elements.ratingsSelectModal));
            
            elements.unratedContentToggle.addEventListener('change', () => {
                parentalControls.allowUnrated = elements.unratedContentToggle.checked;
                saveParentalControls();
            });

            elements.resetPinBtn.addEventListener('click', () => {
                pinEntryCallback = () => {
                    parentalControls.pin = null;
                    currentPinInput = '';
                    isSettingPin = false;
                    tempPin = '';
                    saveParentalControls();
                    updatePinDisplay(elements.pinDisplay, '');
                    elements.pinStatusText.textContent = 'PIN has been reset. Enter a new PIN.';
                    hideModal(elements.pinEntryModal);
                    showModal(elements.parentalControlsModal);
                };
                elements.pinEntryTitle.textContent = "Enter PIN to Reset";
                showModal(elements.pinEntryModal);
            });

            // PIN Entry Modal Listeners
            elements.okPinEntryBtn.addEventListener('click', verifyPin);
            elements.cancelPinEntryBtn.addEventListener('click', () => {
                hideModal(elements.pinEntryModal);
                resetPinEntry();
            });
            elements.closePinEntryModal.addEventListener('click', () => {
                hideModal(elements.pinEntryModal);
                resetPinEntry();
            });
        }

        function isContentAllowed(item) {
            // If no PIN is set, all content is allowed.
            if (!parentalControls.pin) {
                return true;
            }

            const rating = item.parentalRating; // Corrected field name based on user feedback

            if (!rating || rating.trim() === '') {
                // This is unrated content
                return parentalControls.allowUnrated;
            }

            // Check if the item's rating is in the allowed list
            return parentalControls.allowedRatings.includes(rating);
        }

        // --- End Parental Control Logic ---

        // Initialize the app
        document.addEventListener('DOMContentLoaded', () => {
            // Initialize Shaka Player polyfills
            if (window.shaka) {
                shaka.polyfill.installAll();
                console.log('✅ Shaka Player polyfills installed');
            }
            
            init();
            fetchNotification();
        });

        // --- Notification Functionality ---
        const NOTIFICATION_ID_KEY = 'seenNotificationId';

        async function fetchNotification() {
            let notificationData = null;
            try {
                const response = await fetch('https://movie-fcs.fwh.is/cini/notif.json?t=' + new Date().getTime());
                if (!response.ok) throw new Error('Online fetch failed');
                notificationData = await response.json();
                console.log("✅ Loaded notification from ONLINE source");
            } catch (error) {
                console.warn('Online notification fetch failed, trying local fallback:', error);
                try {
                    const response = await fetch('./notif.json?t=' + new Date().getTime());
                    if (!response.ok) throw new Error('Local fetch failed');
                    notificationData = await response.json();
                    console.log("✅ Loaded notification from OFFLINE file");
                } catch (fallbackError) {
                    console.error('Error fetching local notification:', fallbackError);
                }
            }

            if (notificationData && notificationData.notification && notificationData.notification.enabled) {
                const { id, message, link } = notificationData.notification;
                const seenId = localStorage.getItem(NOTIFICATION_ID_KEY);

                if (id !== seenId) {
                    displayNotification(id, message, link);
                }
            }
        }

        function displayNotification(id, message, link) {
            const notificationBar = document.getElementById('notification-bar');
            const notificationMessage = document.getElementById('notification-message');
            const closeBtn = document.getElementById('close-notification');
            const header = document.querySelector('header');

            let messageHtml = message;
            if (link && link !== "#") {
                messageHtml += ` <a href="${link}" target="_blank">Click here</a>`;
            }
            notificationMessage.innerHTML = messageHtml;
            notificationBar.style.display = 'flex';

            // Adjust header position after the notification bar is rendered
            setTimeout(() => {
                if (notificationBar.offsetHeight > 0) {
                    header.style.top = `${notificationBar.offsetHeight}px`;
                }
            }, 100);


            closeBtn.addEventListener('click', () => {
                notificationBar.style.display = 'none';
                header.style.top = '0'; // Reset header position
                localStorage.setItem(NOTIFICATION_ID_KEY, id); // Save the ID of the seen notification
            });
        }
        // --- End Notification Functionality ---

        // Function to find content by ID (Title)
        function findContentById(id) {
            if (!cachedContent || cachedContent.length === 0) {
                console.warn("findContentById: cachedContent is empty or not yet populated.");
                // Optionally, you might need to search in cineData if cachedContent isn't guaranteed
                // to be populated when this is called, though it should be.
                if (cineData && cineData.Categories) {
                    for (const category of cineData.Categories) {
                        const foundEntry = category.Entries.find(entry => entry.Title === id);
                        if (foundEntry) {
                            // Determine type based on category name
                            const type = category.MainCategory.toLowerCase().includes('movie') ? 'movie' :
                                         category.MainCategory.toLowerCase().includes('series') ? 'series' : 'live';
                            return { ...foundEntry, type };
                        }
                    }
                }
                return null;
            }
            return cachedContent.find(item => item.Title === id) || null;
        }

        // --- Episode Navigation Functionality ---
        function updateNavigationButtonsState() {
            const isSeriesContext = currentSeries && currentSeries.type === 'series' && currentSeason && currentEpisode;

            if (!elements.prevEpisodeBtn || !elements.nextEpisodeBtn) {
                console.warn("Episode navigation buttons not created.");
                return;
            }
            
            // Ensure player and its controls are available before trying to manipulate DOM or styles
            if (!playerInstance || !playerInstance.elements || !playerInstance.elements.controls) {
                elements.prevEpisodeBtn.style.display = 'none';
                elements.nextEpisodeBtn.style.display = 'none';
                return;
            }
            
            const controlsContainer = playerInstance.elements.controls;

            if (!isSeriesContext) {
                elements.prevEpisodeBtn.style.display = 'none';
                elements.nextEpisodeBtn.style.display = 'none';
                // Optionally, remove from DOM if they exist and if that becomes necessary,
                // but display:none is simpler for now.
                return;
            }

            // ---- It IS a Series Context from here ----

            // 1. Ensure buttons are in the DOM for series context
            const settingsButton = controlsContainer.querySelector('button[data-plyr="settings"]');
            if (settingsButton) { // Preferred: insert before settings
                if (!controlsContainer.contains(elements.prevEpisodeBtn)) {
                    settingsButton.parentNode.insertBefore(elements.prevEpisodeBtn, settingsButton);
                }
                if (!controlsContainer.contains(elements.nextEpisodeBtn)) {
                    settingsButton.parentNode.insertBefore(elements.nextEpisodeBtn, settingsButton);
                }
            } else { // Fallback: append if settings button isn't found
                if (!controlsContainer.contains(elements.prevEpisodeBtn)) {
                    controlsContainer.appendChild(elements.prevEpisodeBtn);
                }
                if (!controlsContainer.contains(elements.nextEpisodeBtn)) {
                    controlsContainer.appendChild(elements.nextEpisodeBtn);
                }
            }

            // 2. Set visibility
            elements.prevEpisodeBtn.style.display = ''; 
            elements.nextEpisodeBtn.style.display = ''; 

            // 3. Set enabled/disabled state
            const currentEpisodeIndex = currentSeason.Episodes.findIndex(ep => ep.Episode === currentEpisode.Episode);
            const currentSeasonIndex = currentSeries.Seasons.findIndex(s => s.Season === currentSeason.Season);

            elements.prevEpisodeBtn.disabled = !(currentEpisodeIndex > 0 || currentSeasonIndex > 0);
            elements.nextEpisodeBtn.disabled = !(currentEpisodeIndex < currentSeason.Episodes.length - 1 || currentSeasonIndex < currentSeries.Seasons.length - 1);
        }

        function handlePrevEpisode() {
            if (!currentSeries || !currentSeason || !currentEpisode) return;

            const currentEpisodeIndex = currentSeason.Episodes.findIndex(ep => ep.Episode === currentEpisode.Episode);
            const currentSeasonIndex = currentSeries.Seasons.findIndex(s => s.Season === currentSeason.Season);

            if (currentEpisodeIndex > 0) {
                // Play previous episode in the same season
                playEpisode(currentSeason.Episodes[currentEpisodeIndex - 1], true);
            } else {
                // First episode of current season, try to go to previous season
                if (currentSeasonIndex > 0) {
                    const prevSeasonData = currentSeries.Seasons[currentSeasonIndex - 1];
                    if (prevSeasonData.Episodes && prevSeasonData.Episodes.length > 0) {
                        currentSeason = prevSeasonData; // Set current season context

                        // Update the episode selector for the new (previous) season
                        elements.episodeSelector.innerHTML = '<option value="">Select Episode</option>';
                        currentSeason.Episodes.forEach(ep => {
                            const option = document.createElement('option');
                            option.value = ep.Episode;
                            option.textContent = `Episode ${ep.Episode}: ${ep.Title}`;
                            elements.episodeSelector.appendChild(option);
                        });

                        // Play the last episode of this (newly set current) previous season
                        const targetEpisode = currentSeason.Episodes[currentSeason.Episodes.length - 1];
                        playEpisode(targetEpisode, true);
                    }
                }
            }
        }

        function handleNextEpisode() {
            if (!currentSeries || !currentSeason || !currentEpisode) return;

            const currentEpisodeIndex = currentSeason.Episodes.findIndex(ep => ep.Episode === currentEpisode.Episode);
            const currentSeasonIndex = currentSeries.Seasons.findIndex(s => s.Season === currentSeason.Season);

            if (currentEpisodeIndex < currentSeason.Episodes.length - 1) {
                // Play next episode in the same season
                playEpisode(currentSeason.Episodes[currentEpisodeIndex + 1], true);
            } else {
                // Last episode of current season, try to go to next season
                if (currentSeasonIndex < currentSeries.Seasons.length - 1) {
                    const nextSeasonData = currentSeries.Seasons[currentSeasonIndex + 1];
                    openSeason(nextSeasonData); // This will auto-play the first episode of nextSeasonData
                }
            }
        }
        // --- End Episode Navigation Functionality ---

        // --- Share Functionality ---
        function displayTemporaryMessage(element, message) {
            const originalText = element.textContent;
            element.textContent = message;
            setTimeout(() => {
                element.textContent = originalText;
            }, 2000); // Display message for 2 seconds
        }

        async function handleShareVideo() {
            if (!currentContentInfo || !currentContentInfo.Title) {
                console.warn("No content info to share.");
                return;
            }

            let shareTitle = currentContentInfo.Title;
            let shareText = `Watch "${currentContentInfo.Title}" on CineCraze!`;
            const shareUrl = window.location.href; // The URL of the current page

            // If it's a series and a specific episode is selected, use episode details
            if (currentContentInfo.type === 'series' && currentEpisode && currentEpisode.Title) {
                shareTitle = `${currentSeries.Title} - ${currentEpisode.Title}`;
                shareText = `Watch "${currentSeries.Title} - Episode ${currentEpisode.Episode}: ${currentEpisode.Title}" on CineCraze!`;
            } else if (currentContentInfo.type === 'series' && currentSeries && currentSeries.Title) {
                // Fallback for series if no specific episode is playing but series context is known
                shareTitle = currentSeries.Title;
                shareText = `Check out the series "${currentSeries.Title}" on CineCraze!`;
            }


            const shareData = {
                title: `Check out: ${shareTitle}`,
                text: shareText,
                url: shareUrl
            };

            if (navigator.share) {
                try {
                    await navigator.share(shareData);
                    console.log('Content shared successfully');
                } catch (err) {
                    console.error('Error sharing content:', err);
                    if (err.name !== 'AbortError') {
                        navigator.clipboard.writeText(shareData.url).then(() => {
                            displayTemporaryMessage(elements.shareVideoBtn.querySelector('span'), 'Link Copied!');
                        }).catch(clipErr => {
                            console.error('Fallback clipboard error:', clipErr);
                            alert('Failed to copy link.');
                        });
                    }
                }
            } else {
                try {
                    await navigator.clipboard.writeText(shareData.url);
                    displayTemporaryMessage(elements.shareVideoBtn.querySelector('span'), 'Link Copied!');
                } catch (err) {
                    console.error('Could not copy text: ', err);
                    alert('Failed to copy link. Please copy it manually: ' + shareData.url);
                }
            }
        }
        // --- End Share Functionality ---

        // --- Server-Side Interaction Logic ---
        const USER_INTERACTION_STATE = 'cineCrazeUserInteractionState';

        function getLocalUserAction(contentId) {
            const allStates = JSON.parse(localStorage.getItem(USER_INTERACTION_STATE)) || {};
            return allStates[contentId] || null; // 'liked' or 'disliked'
        }

        function setLocalUserAction(contentId, action) {
            const allStates = JSON.parse(localStorage.getItem(USER_INTERACTION_STATE)) || {};
            if (action) {
                allStates[contentId] = action;
            } else {
                delete allStates[contentId];
            }
            localStorage.setItem(USER_INTERACTION_STATE, JSON.stringify(allStates));
        }

        async function postInteraction(action, content) {
            try {
                const response = await fetch('api/interactions.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: action,
                        content_id: content.id,
                        content_type: content.type
                    })
                });
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                const result = await response.json();
                if (result.status === 'success') {
                    updateInteractionUI(result.counts);
                }
            } catch (error) {
                console.error('Failed to post interaction:', error);
            }
        }

        function formatCount(count) {
            if (count >= 1000000) return (count / 1000000).toFixed(1).replace(/\.0$/, '') + 'M';
            if (count >= 1000) return (count / 1000).toFixed(1).replace(/\.0$/, '') + 'K';
            return count.toString();
        }

        function updateInteractionUI(counts) {
            const likeCountSpan = document.getElementById('like-count-span');
            const dislikeCountSpan = document.getElementById('dislike-count-span');
            const viewsElement = document.getElementById('viewer-views');

            if (counts.likes !== undefined) likeCountSpan.textContent = formatCount(counts.likes);
            if (counts.dislikes !== undefined) dislikeCountSpan.textContent = formatCount(counts.dislikes);
            if (counts.views !== undefined) viewsElement.innerHTML = `<i class="fas fa-eye"></i> ${formatCount(counts.views)} views`;
        }

        async function fetchInitialCounts(content) {
            const likeCheckbox = document.getElementById('like-checkbox');
            const dislikeCheckbox = document.getElementById('dislike-checkbox');
            likeCheckbox.checked = getLocalUserAction(content.id) === 'liked';
            dislikeCheckbox.checked = getLocalUserAction(content.id) === 'disliked';
            await postInteraction('get', content);
        }

        function handleLike() {
            if (!currentContentInfo || !currentContentInfo.id) return;
            const contentId = currentContentInfo.id;
            const currentAction = getLocalUserAction(contentId);

            if (currentAction === 'liked') {
                // User is unliking
                postInteraction('unlike', currentContentInfo);
                setLocalUserAction(contentId, null);
            } else {
                // User is liking
                if (currentAction === 'disliked') {
                    postInteraction('undislike', currentContentInfo);
                }
                postInteraction('like', currentContentInfo);
                setLocalUserAction(contentId, 'liked');
            }
             // Immediately update UI for responsiveness
            updateLikeDislikeUI(contentId);
        }

        function handleDislike() {
             if (!currentContentInfo || !currentContentInfo.id) return;
            const contentId = currentContentInfo.id;
            const currentAction = getLocalUserAction(contentId);

            if (currentAction === 'disliked') {
                // User is undisliking
                postInteraction('undislike', currentContentInfo);
                setLocalUserAction(contentId, null);
            } else {
                // User is disliking
                if (currentAction === 'liked') {
                    postInteraction('unlike', currentContentInfo);
                }
                postInteraction('dislike', currentContentInfo);
                setLocalUserAction(contentId, 'disliked');
            }
             // Immediately update UI for responsiveness
            updateLikeDislikeUI(contentId);
        }

        function incrementViewCount() {
            if (!currentContentInfo || !currentContentInfo.id) return;
            postInteraction('view', currentContentInfo);
        }
        // --- End Server-Side Interaction Logic ---


        // --- Watch Later Functionality ---
        async function toggleWatchLater(content, buttonElement) {
            if (!content || !content.Title) return;
            const contentId = content.Title;
            const db = await watchLaterDbUtil.open();
            const existing = await watchLaterDbUtil.get(db, contentId);

            if (existing) {
                await watchLaterDbUtil.delete(db, contentId);
                alert(`"${content.Title}" removed from Watch Later.`);
                watchLaterItemsSet.delete(contentId);
                if (buttonElement) buttonElement.classList.remove('active');
            } else {
                // Ensure the object we store has an 'id' property for the keyPath
                const itemToStore = { ...content, id: contentId };
                await watchLaterDbUtil.set(db, itemToStore);
                alert(`"${content.Title}" added to Watch Later.`);
                watchLaterItemsSet.add(contentId);
                if (buttonElement) buttonElement.classList.add('active');
            }
            db.close();
            updateWatchLaterButton(contentId);
        }

        async function updateWatchLaterButton(contentId) {
            const watchLaterBtn = document.getElementById('watch-later-btn');
            if (!watchLaterBtn) return;

            const db = await watchLaterDbUtil.open();
            const existing = await watchLaterDbUtil.get(db, contentId);
            db.close();

            if (existing) {
                watchLaterBtn.classList.add('active');
            } else {
                watchLaterBtn.classList.remove('active');
            }
        }

        async function deleteFromWatchLater(contentId) {
            if (!contentId) return;
            const db = await watchLaterDbUtil.open();
            await watchLaterDbUtil.delete(db, contentId);
            db.close();
            console.log('Removed from Watch Later:', contentId);
            watchLaterItemsSet.delete(contentId); // Sync the cache
            await renderContent('watch-later');
        }
        // --- End Watch Later Functionality ---
        // --- End View Counter Functionality ---

        // --- Stretch Functionality ---
        function toggleStretch() {
            isStretched = !isStretched;
            const videoElement = playerInstance.elements.container.querySelector('video');
            if (videoElement) {
                if (isStretched) {
                    videoElement.style.objectFit = 'fill';
                    elements.stretchBtn.classList.add('active');
                } else {
                    videoElement.style.objectFit = 'contain';
                    elements.stretchBtn.classList.remove('active');
                }
            }
        }
        // --- Picture-in-Picture Button for Android ---
function addPipButtonToPlayer() {
    if (!playerInstance || !playerInstance.elements || !playerInstance.elements.controls) {
        return;
    }

    const controlsContainer = playerInstance.elements.controls;
    const settingsButton = controlsContainer.querySelector('button[data-plyr="settings"]');

    // Create the button
    const pipButton = document.createElement('button');
    pipButton.type = 'button';
    pipButton.className = 'plyr__controls__item plyr__control';
    pipButton.innerHTML = '<i class="fas fa-external-link-alt"></i>'; // You can change this icon
    pipButton.setAttribute('aria-label', 'Picture-in-Picture');

    // Add event listener
    pipButton.addEventListener('click', () => {
        if (typeof Android !== 'undefined' && Android.enterPictureInPicture) {
            Android.enterPictureInPicture();
        }
    });

    // Insert before the settings button
    if (settingsButton) {
        settingsButton.parentNode.insertBefore(pipButton, settingsButton);
    } else {
        controlsContainer.appendChild(pipButton);
    }
}

// Call this function after the player is initialized
playerInstance.on('ready', event => {
    addPipButtonToPlayer();
});
        // --- End Stretch Functionality ---

    </script>

</body>
</h