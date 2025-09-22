<?php
session_start();
require_once '../includes/config.php';

// If user is already logged in, redirect to admin dashboard
if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
    header('Location: index.php');
    exit;
}

$error_message = '';
$show_hash_generator = true;

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username'])) {
    $show_hash_generator = false;
    $conn = db_connect();

    $username = $_POST['username'];
    $password = $_POST['password'];

    if (!empty($username) && !empty($password)) {
        $stmt = $conn->prepare("SELECT id, username, password FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            if (password_verify($password, $user['password'])) {
                // Password is correct, start a new session
                session_regenerate_id();
                $_SESSION['loggedin'] = true;
                $_SESSION['id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                header('Location: index.php');
                exit;
            } else {
                $error_message = 'Invalid password.';
            }
        } else {
            $error_message = 'No user found with that username.';
        }
        $stmt->close();
    } else {
        $error_message = 'Please fill in both fields.';
    }
    $conn->close();
}

// Handle password hash generation
$new_hash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_password'])) {
    $new_password = $_POST['new_password'];
    if (!empty($new_password)) {
        $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - CineCraze</title>
    <style>
        :root {
            --primary: #e50914;
            --background: #141414;
            --surface: #1a1a1a;
            --text: #ffffff;
            --text-secondary: #b3b3b3;
            --danger: #f40612;
            --border-radius: 8px;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background-color: var(--background);
            color: var(--text);
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
        }
        .login-container {
            background-color: var(--surface);
            padding: 40px;
            border-radius: var(--border-radius);
            box-shadow: 0 8px 32px rgba(0,0,0,0.4);
            width: 100%;
            max-width: 400px;
            text-align: center;
        }
        h1 {
            color: var(--primary);
            margin-bottom: 20px;
        }
        .form-group {
            margin-bottom: 20px;
            text-align: left;
        }
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text-secondary);
        }
        input[type="text"], input[type="password"] {
            width: 100%;
            padding: 12px;
            border: 1px solid #333;
            border-radius: var(--border-radius);
            background-color: #222;
            color: var(--text);
            font-size: 16px;
        }
        .btn {
            width: 100%;
            padding: 12px;
            border: none;
            border-radius: var(--border-radius);
            background-color: var(--primary);
            color: white;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.3s ease;
        }
        .btn:hover {
            background-color: #b8070f;
        }
        .error {
            color: var(--danger);
            background-color: rgba(244, 6, 18, 0.1);
            border: 1px solid var(--danger);
            padding: 10px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
        }
        .hash-generator {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #333;
        }
        .hash-result {
            background-color: #111;
            padding: 10px;
            border-radius: 4px;
            word-wrap: break-word;
            margin-top: 10px;
            font-family: monospace;
            border: 1px solid #444;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <h1>CineCraze Admin</h1>

        <?php if (!empty($error_message)): ?>
            <div class="error"><?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>

        <form action="login.php" method="post">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" name="username" id="username" required>
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" name="password" id="password" required>
            </div>
            <button type="submit" class="btn">Login</button>
        </form>

        <?php if ($show_hash_generator): ?>
        <div class="hash-generator">
            <h2>Password Hash Generator</h2>
            <p style="color: var(--text-secondary); font-size: 14px;">
                Use this to generate a new password hash for your <code>config.php</code> or database.
            </p>
            <form action="login.php" method="post">
                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <input type="text" name="new_password" id="new_password" required>
                </div>
                <button type="submit" class="btn">Generate Hash</button>
            </form>
            <?php if (!empty($new_hash)): ?>
                <div class="hash-result" onclick="this.select()"><?php echo htmlspecialchars($new_hash); ?></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
