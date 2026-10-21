<?php
// One-time setup: creates the first admin account. Works only while no users exist.
require_once __DIR__ . '/includes/functions.php';
verify_csrf();
if ((int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn() > 0) {
    exit('Setup already completed. Please <a href="login.php">log in</a>. (You may delete setup.php.)');
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['full_name'] ?? '');
    $user = trim($_POST['username'] ?? '');
    $pass = $_POST['password'] ?? '';
    if ($name === '' || $user === '' || strlen($pass) < 6) {
        $error = 'All fields are required and the password must be at least 6 characters.';
    } else {
        $pdo->prepare("INSERT INTO users (full_name, username, password, role) VALUES (?,?,?, 'admin')")
            ->execute([$name, $user, password_hash($pass, PASSWORD_DEFAULT)]);
        flash('success', 'Admin account created. You can now log in.');
        redirect('login.php');
    }
}
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Setup | <?= APP_NAME ?></title><link rel="stylesheet" href="assets/style.css"></head>
<body>
<div class="login-box">
    <h1>First-Time Setup</h1>
    <p class="muted">Create the shop owner's admin account.</p>
    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <form method="post">
        <?= csrf_field() ?>
        <label>Full name</label><input name="full_name" required>
        <label>Username</label><input name="username" required>
        <label>Password (min 6 characters)</label><input type="password" name="password" required minlength="6">
        <p><button class="btn" style="width:100%">Create Admin</button></p>
    </form>
</div>
</body></html>
