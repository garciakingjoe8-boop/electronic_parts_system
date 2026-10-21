<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (ob_get_level() === 0) ob_start(); // allow redirects after page output starts
require_once __DIR__ . '/../config/database.php';

const APP_NAME = '2K2R Electronic Parts Shop';

function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function money($v): string { return '₱' . number_format((float)$v, 2); }
function redirect(string $url): void { header("Location: $url"); exit; }

function is_logged_in(): bool { return !empty($_SESSION['user_id']); }
function is_admin(): bool { return ($_SESSION['role'] ?? '') === 'admin'; }

function require_login(): void {
    if (!is_logged_in()) redirect('login.php');
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
        if (!$ok) { http_response_code(400); exit('Invalid request token. Go back and try again.'); }
    }
}
