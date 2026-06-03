<?php
session_start();
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: dashboard.php'); exit();
}
require_once 'db_connect.php';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = clean($conn, $_POST['username'] ?? '');
    $password = clean($conn, $_POST['password'] ?? '');
    if ($username === '' || $password === '') {
        $error = 'Please enter both username and password.';
    } else {
        $hashed = MD5($password);
        $row = db_fetch_one($conn, "SELECT admin_id, username FROM admin WHERE username='$username' AND password='$hashed'");
        if ($row) {
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_id']        = $row['admin_id'];
            $_SESSION['admin_username']  = $row['username'];
            header('Location: dashboard.php'); exit();
        } else {
            $error = 'Invalid username or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Login — Resort Management</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="login-wrapper">
    <div class="login-box">
        <h2>🏨 Resort Management</h2>
        <p class="subtitle">Admin Panel — Please sign in</p>
        <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <form method="POST">
            <div class="form-group">
                <label>Username</label>
                <input type="text" name="username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required autofocus>
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" required>
            </div>
            <button type="submit" class="btn btn-primary btn-block">Sign In</button>
        </form>
        <p style="text-align:center;margin-top:16px;font-size:12px;color:#a0aec0;">Default: admin / admin123</p>
    </div>
</div>
</body>
</html>
