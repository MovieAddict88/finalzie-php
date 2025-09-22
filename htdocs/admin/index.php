<?php
require_once 'partials/header.php';
?>

<!-- TMDB Generator Tab Content -->
<div id="tmdb-generator">
    <!-- API Key Selection Card can be added later as a global setting -->

    <div class="grid grid-2">
        <div class="card">
            <h2>🎬 Movie Generator</h2>
            <form action="index.php" method="POST">
                <div class="form-group">
                    <label>TMDB Movie ID</label>
                    <input type="number" name="movie_tmdb_id" placeholder="e.g., 550 (Fight Club)">
                </div>
                <!-- Additional Servers functionality will be added with JS later -->
                <div class="form-group">
                    <label>Additional Servers (Feature coming soon)</label>
                    <div class="server-list">
                        <div class="server-item">
                            <input type="text" placeholder="Server Name" disabled>
                            <input type="url" placeholder="Video URL" disabled>
                        </div>
                    </div>
                </div>
                <button type="submit" name="generate_movie" class="btn btn-primary">
                    Generate Movie
                </button>
            </form>
        </div>

        <div class="card">
            <h2>📺 TV Series Generator</h2>
            <form action="index.php" method="POST">
                <div class="form-group">
                    <label>TMDB TV Series ID</label>
                    <input type="number" name="series_tmdb_id" placeholder="e.g., 1399 (Game of Thrones)">
                </div>
                <div class="form-group">
                    <label>Seasons to Include</label>
                    <input type="text" name="series_seasons" placeholder="e.g., 1,2,3 or leave empty for all">
                </div>
                <!-- Additional Servers functionality will be added with JS later -->
                <div class="form-group">
                     <label>Additional Servers (Feature coming soon)</label>
                    <div class="server-list">
                        <div class="server-item">
                            <input type="text" placeholder="Server Name" disabled>
                            <input type="url" placeholder="Video URL Template" disabled>
                        </div>
                    </div>
                </div>
                <button type="submit" name="generate_series" class="btn btn-primary">
                    Generate Series
                </button>
            </form>
        </div>
    </div>

    <!-- TMDB Search & Preview will be a major feature to implement next -->
    <div class="card">
        <h2>🔍 TMDB Search & Preview (Coming Soon)</h2>
        <p>This section will allow searching TMDB and generating content directly from search results.</p>
    </div>
</div>


<?php
require_once 'partials/footer.php';
?>
