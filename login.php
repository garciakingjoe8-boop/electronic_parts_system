<?php
require_once __DIR__ . '/includes/functions.php';
verify_csrf();
$count = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
if ($count === 0) redirect('setup.php');
if (is_logged_in()) redirect('index.php');

$error = '';
$username = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if ($user && password_verify($_POST['password'] ?? '', $user['password'])) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['role'] = $user['role'];
        redirect('index.php');
    }
    $error = 'Invalid username or password.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login | <?= APP_NAME ?></title>
<link rel="stylesheet" href="assets/login.css">
</head>
<body>
<div class="stage">
    <div class="banner" aria-hidden="true"></div>
    <main class="card">
        <svg class="logo" viewBox="0 0 64 64" fill="none" stroke="#d9a85f" stroke-width="2" stroke-linejoin="round" aria-hidden="true">
            <path d="M32 4 56 18v28L32 60 8 46V18z"/><path d="M32 18 46 26v16L32 50 18 42V26z"/>
            <path d="M32 4v14M56 18 46 26M8 18l10 8M32 50v10M46 42l10 4M18 42 8 46" stroke-width="1.4" opacity=".8"/>
        </svg>
        <h1>Welcome back</h1>
        <?php show_flash(); ?>
        <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
        <form method="post" autocomplete="on">
            <?= csrf_field() ?>
            <label for="username">Username</label>
            <div class="field">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.4 3.6-7 8-7s8 2.6 8 7"/></svg>
                <input id="username" name="username" value="<?= e($username) ?>" placeholder="Enter your username" required autofocus autocomplete="username">
            </div>
            <label for="password">Password</label>
            <div class="field">
                <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>
                <input id="password" type="password" name="password" placeholder="Enter your password" required autocomplete="current-password">
                <button type="button" class="eye" id="eye" aria-label="Show password">
                    <svg viewBox="0 0 24 24"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
                </button>
            </div>
            <button class="btn" type="submit">Login
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
            </button>
        </form>
        <div class="foot">Don’t have an account? <b>Ask the shop admin.</b></div>
    </main>
</div>
<script>
const pw = document.getElementById('password');
document.getElementById('eye').addEventListener('click', () => { pw.type = pw.type === 'password' ? 'text' : 'password'; });
</script>
</body>
</html>
