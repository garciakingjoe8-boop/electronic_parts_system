<?php
const SESSION_IDLE_SECONDS = 1800;   // auto-logout after 30 minutes without activity

// ---- security hardening: session cookie + response headers ----
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0,                       // cookie ends when the browser closes
        'path'     => '/',
        'httponly' => true,                    // JavaScript cannot read the session cookie
        'samesite' => 'Lax',                   // blocks cross-site form posts
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
    session_start();
}
// Never let the browser keep a copy of a page, so Back after logout cannot show private data
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header_remove('X-Powered-By');
ob_start(); // always add our own unlimited buffer so redirects still work after page output (XAMPP's default buffer is only 4096 bytes)
require_once __DIR__ . '/../config/database.php';

const APP_NAME = '2K2R Electronic Parts Shop';

function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function money($v): string { return '₱' . number_format((float)$v, 2); }
function redirect(string $url): void { header("Location: $url"); exit; }

function is_logged_in(): bool { return !empty($_SESSION['user_id']); }
function is_admin(): bool { return ($_SESSION['role'] ?? '') === 'admin'; }

function end_session(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
}

function require_login(): void {
    global $pdo;
    if (!is_logged_in()) redirect('login.php');

    // idle timeout
    if (time() - ($_SESSION['last_active'] ?? time()) > SESSION_IDLE_SECONDS) {
        end_session();
        session_start();
        flash('error', 'You were logged out after 30 minutes of inactivity. Please log in again.');
        redirect('login.php');
    }
    $_SESSION['last_active'] = time();

    // the account must still exist; role/name always come from the database (so changes apply immediately)
    $st = $pdo->prepare("SELECT username, full_name, role FROM users WHERE user_id = ?");
    $st->execute([$_SESSION['user_id']]);
    $u = $st->fetch();
    if (!$u) {
        end_session();
        session_start();
        flash('error', 'Your account is no longer available.');
        redirect('login.php');
    }
    $_SESSION['username'] = $u['username'];
    $_SESSION['full_name'] = $u['full_name'];
    $_SESSION['role'] = $u['role'];
}
function require_admin(): void {
    require_login();
    if (!is_admin()) {
        flash('error', 'Only the admin can access that page.');
        redirect('index.php');
    }
}

function flash(string $type, string $msg): void { $_SESSION['flash'][] = [$type, $msg]; }
function show_flash(): void {
    foreach ($_SESSION['flash'] ?? [] as $f) {
        echo '<div class="alert alert-' . e($f[0]) . '">' . e($f[1]) . '</div>';
    }
    unset($_SESSION['flash']);
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}
function verify_csrf(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $ok = isset($_POST['csrf']) && hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf']);
        if (!$ok) {
            // Usually means the page was open too long, the session expired, or it was opened in another tab.
            flash('error', 'That page was out of date. Please try again.');
            $back = 'login.php';
            if (is_logged_in()) {
                $ref = basename((string)parse_url($_SERVER['HTTP_REFERER'] ?? '', PHP_URL_PATH));
                $back = preg_match('/^[a-z_]+\.php$/', $ref) && $ref !== 'login.php' ? $ref : 'index.php';
            }
            redirect($back);
        }
    }
}
