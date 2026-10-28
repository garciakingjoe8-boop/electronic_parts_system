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
    $wait = (int)($_SESSION['lock_until'] ?? 0) - time();
    if ($wait > 0) {
        $error = "Too many failed attempts. Please wait $wait seconds and try again.";
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        if ($user && password_verify($_POST['password'] ?? '', $user['password'])) {
            session_regenerate_id(true);
            unset($_SESSION['fails'], $_SESSION['lock_until']);
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['last_active'] = time();
            redirect('index.php');
        }
        usleep(400000);                                   // slow down guessing
        $_SESSION['fails'] = ($_SESSION['fails'] ?? 0) + 1;
        if ($_SESSION['fails'] >= 5) {                    // 5 wrong tries -> 60 second lock
            $_SESSION['lock_until'] = time() + 60;
            $_SESSION['fails'] = 0;
            $error = 'Too many failed attempts. Please wait 60 seconds and try again.';
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
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login | <?= APP_NAME ?></title>
<link rel="stylesheet" href="assets/login3d.css">
</head>
<body>
<div class="bgfx" aria-hidden="true"><span class="blob b1"></span><span class="blob b2"></span></div>
<div class="scene">
  <div class="enter">
    <div class="panel" id="panel">
      <div class="glass" id="glass"></div>

      <div class="p-nav">
        <div class="p-brand">
          <svg width="28" height="28" viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linejoin="round" aria-hidden="true"><path d="M32 4 56 18v28L32 60 8 46V18z"/><path d="M32 18 46 26v16L32 50 18 42V26z"/><path d="M32 4v14M56 18 46 26M8 18l10 8M32 50v10M46 42l10 4M18 42 8 46" stroke-width="1.6" opacity=".8"/></svg>
          2K2R Parts
        </div>
        <div class="p-tag">Inventory &amp; Sales System</div>
      </div>

      <div class="hero" aria-hidden="true">
        <div class="glow"></div>
        <div class="ring r2"></div><div class="ring r1"></div><div class="ring r3"></div>
        <div class="fig"><div class="fig-float"><img src="assets/login-hero.png" alt="" draggable="false"></div></div>
      </div>

      <div class="signin">
        <div class="s-box">
          <h1>Hello !<br>Welcome Back</h1>
          <?php show_flash(); ?>
          <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
          <form method="post" autocomplete="off">
            <?= csrf_field() ?>
            <div class="in">
              <input name="username" value="<?= e($username) ?>" placeholder="Enter Username" required autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" data-lpignore="true" data-form-type="other" readonly onfocus="this.removeAttribute('readonly')" aria-label="Username">
              <svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.4 3.6-7 8-7s8 2.6 8 7"/></svg>
            </div>
            <div class="in">
              <input id="pw" type="password" name="password" placeholder="Enter Password" required autocomplete="current-password" aria-label="Password">
              <button type="button" class="ico" id="eye" aria-label="Show password"><svg viewBox="0 0 24 24"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg></button>
            </div>
            <span class="hint">Forgot password? Ask the admin</span>
            <button class="go" type="submit">Sign In</button>
          </form>
          <div class="divider">Staff access only</div>
          <div class="foot">Don’t have an account? <b>Ask the shop admin</b></div>
        </div>
      </div>
    </div>
  </div>
</div>
<script>
window.addEventListener('pageshow', function (e) { if (e.persisted) location.reload(); });
// show / hide password
var pw = document.getElementById('pw');
document.getElementById('eye').addEventListener('click', function () { pw.type = pw.type === 'password' ? 'text' : 'password'; });

// 3D tilt that follows the mouse (smoothed)
(function () {
    var panel = document.getElementById('panel'), glass = document.getElementById('glass');
    var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reduce || !window.matchMedia('(hover:hover)').matches) return;
    var tx = 0, ty = 0, cx = 0, cy = 0, running = false;
    function loop() {
        cx += (tx - cx) * 0.08; cy += (ty - cy) * 0.08;
        panel.style.setProperty('--rx', cx.toFixed(2) + 'deg');
        panel.style.setProperty('--ry', cy.toFixed(2) + 'deg');
        if (Math.abs(tx - cx) > 0.01 || Math.abs(ty - cy) > 0.01) requestAnimationFrame(loop); else running = false;
    }
    window.addEventListener('mousemove', function (e) {
        var nx = e.clientX / window.innerWidth - 0.5, ny = e.clientY / window.innerHeight - 0.5;
        tx = -ny * 12; ty = nx * 16;
        var r = glass.getBoundingClientRect();
        glass.style.setProperty('--mx', ((e.clientX - r.left) / r.width * 100).toFixed(1) + '%');
        glass.style.setProperty('--my', ((e.clientY - r.top) / r.height * 100).toFixed(1) + '%');
        if (!running) { running = true; requestAnimationFrame(loop); }
    });
    document.addEventListener('mouseleave', function () { tx = 0; ty = 0; if (!running) { running = true; requestAnimationFrame(loop); } });
})();
</script>
</body>
</html>
