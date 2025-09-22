<?php
require_once 'includes/config.php';

// Get database connection
$conn = new mysqli(DB_HOST, DB_USERNAME, DB_PASSWORD);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Create database if it doesn't exist
$sql = "CREATE DATABASE IF NOT EXISTS " . DB_NAME;
if ($conn->query($sql) === TRUE) {
    echo "Database created successfully or already exists.<br>";
} else {
    die("Error creating database: " . $conn->error);
}

// Select the database
$conn->select_db(DB_NAME);

// --- Table Creation Queries ---

// Users table for admin login
$sql_users = "
CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

// Settings table for admin panel configurations
$sql_settings = "
CREATE TABLE IF NOT EXISTS `settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(255) NOT NULL,
  `setting_value` text NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

// Movies table
$sql_movies = "
CREATE TABLE IF NOT EXISTS `movies` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tmdb_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `description` text,
  `poster_path` varchar(255) DEFAULT NULL,
  `backdrop_path` varchar(255) DEFAULT NULL,
  `release_date` date DEFAULT NULL,
  `runtime` int(11) DEFAULT NULL,
  `rating` decimal(3,1) DEFAULT NULL,
  `parental_rating` varchar(20) DEFAULT NULL,
  `trailer_url` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tmdb_id` (`tmdb_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

// TV Series table
$sql_tv_series = "
CREATE TABLE IF NOT EXISTS `tv_series` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tmdb_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `description` text,
  `poster_path` varchar(255) DEFAULT NULL,
  `backdrop_path` varchar(255) DEFAULT NULL,
  `first_air_date` date DEFAULT NULL,
  `rating` decimal(3,1) DEFAULT NULL,
  `parental_rating` varchar(20) DEFAULT NULL,
  `trailer_url` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tmdb_id` (`tmdb_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

// Live TV table
$sql_live_tv = "
CREATE TABLE IF NOT EXISTS `live_tv` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `description` text,
  `poster_path` varchar(255) DEFAULT NULL,
  `backdrop_path` varchar(255) DEFAULT NULL,
  `rating` decimal(3,1) DEFAULT NULL,
  `parental_rating` varchar(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

// Seasons table
$sql_seasons = "
CREATE TABLE IF NOT EXISTS `seasons` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `series_id` int(11) NOT NULL,
  `season_number` int(11) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `poster_path` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `series_season` (`series_id`,`season_number`),
  FOREIGN KEY (`series_id`) REFERENCES `tv_series`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

// Episodes table
$sql_episodes = "
CREATE TABLE IF NOT EXISTS `episodes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `season_id` int(11) NOT NULL,
  `episode_number` int(11) NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `description` text,
  `still_path` varchar(255) DEFAULT NULL,
  `runtime` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `season_episode` (`season_id`,`episode_number`),
  FOREIGN KEY (`season_id`) REFERENCES `seasons`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

// Servers table (polymorphic)
$sql_servers = "
CREATE TABLE IF NOT EXISTS `servers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `content_id` int(11) NOT NULL,
  `content_type` enum('movie','episode','live') NOT NULL,
  `server_name` varchar(255) NOT NULL,
  `server_url` text NOT NULL,
  `quality` varchar(50) DEFAULT NULL,
  `is_embed` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `content_idx` (`content_id`,`content_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

// Genres table
$sql_genres = "
CREATE TABLE IF NOT EXISTS `genres` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tmdb_id` int(11) DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`),
  UNIQUE KEY `tmdb_id` (`tmdb_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

// Content-Genres pivot table
$sql_content_genres = "
CREATE TABLE IF NOT EXISTS `content_genres` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `content_id` int(11) NOT NULL,
  `content_type` enum('movie','tv_series') NOT NULL,
  `genre_id` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `content_genre` (`content_id`,`content_type`,`genre_id`),
  FOREIGN KEY (`genre_id`) REFERENCES `genres`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

// Interactions table for likes, dislikes, views
$sql_interactions = "
CREATE TABLE IF NOT EXISTS `interactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `content_id` int(11) NOT NULL,
  `content_type` enum('movie','tv_series') NOT NULL,
  `views` int(11) NOT NULL DEFAULT '0',
  `likes` int(11) NOT NULL DEFAULT '0',
  `dislikes` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `content_interaction` (`content_id`,`content_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

// Array of table creation queries
$tables = [
    'users' => $sql_users,
    'settings' => $sql_settings,
    'movies' => $sql_movies,
    'tv_series' => $sql_tv_series,
    'live_tv' => $sql_live_tv,
    'seasons' => $sql_seasons,
    'episodes' => $sql_episodes,
    'servers' => $sql_servers,
    'genres' => $sql_genres,
    'content_genres' => $sql_content_genres,
    'interactions' => $sql_interactions
];

// Execute all table creation queries
foreach ($tables as $tableName => $sql) {
    if ($conn->query($sql) === TRUE) {
        echo "Table `{$tableName}` created successfully or already exists.<br>";
    } else {
        echo "Error creating table `{$tableName}`: " . $conn->error . "<br>";
    }
}

// --- Initial Data Seeding ---

// Check if admin user exists, if not, insert the default one
$result = $conn->query("SELECT id FROM `users` WHERE username = '" . ADMIN_USERNAME . "'");
if ($result->num_rows == 0) {
    $sql_insert_admin = "INSERT INTO `users` (username, password) VALUES ('" . ADMIN_USERNAME . "', '" . ADMIN_PASSWORD_HASH . "')";
    if ($conn->query($sql_insert_admin) === TRUE) {
        echo "Default admin user created successfully.<br>";
    } else {
        echo "Error creating default admin user: " . $conn->error . "<br>";
    }
} else {
    echo "Default admin user already exists.<br>";
}

echo "<h2>Setup Complete!</h2>";
echo "<p>You can now delete this `setup.php` file for security.</p>";
echo "<p><a href='/admin/login.php'>Go to Admin Login</a></p>";

$conn->close();
?>
