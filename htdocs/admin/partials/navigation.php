<?php
// Get the current page name
$current_page = basename($_SERVER['PHP_SELF']);
?>
<nav class="bottom-nav" role="navigation" aria-label="Main navigation">
    <div class="nav-container">
        <a href="index.php" class="nav-item <?php echo ($current_page === 'index.php') ? 'active' : ''; ?>" aria-label="TMDB Generator">
            <div class="nav-icon">🎭</div>
            <div class="nav-label">TMDB</div>
        </a>
        <a href="manual.php" class="nav-item <?php echo ($current_page === 'manual.php') ? 'active' : ''; ?>" aria-label="Manual Input">
            <div class="nav-icon">✏️</div>
            <div class="nav-label">Manual</div>
        </a>
        <a href="bulk.php" class="nav-item <?php echo ($current_page === 'bulk.php') ? 'active' : ''; ?>" aria-label="Bulk Operations">
            <div class="nav-icon">📦</div>
            <div class="nav-label">Bulk</div>
        </a>
        <a href="data.php" class="nav-item <?php echo ($current_page === 'data.php') ? 'active' : ''; ?>" aria-label="Data Management">
            <div class="nav-icon">🗂️</div>
            <div class="nav-label">Data</div>
        </a>
    </div>
</nav>
