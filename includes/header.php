<?php
require_once __DIR__ . '/functions.php';
require_login();
verify_csrf();
$current = basename($_SERVER['PHP_SELF']);
$activeMap = ['sale_view.php' => 'sales.php', 'product_form.php' => 'products.php'];
$current = $activeMap[$current] ?? $current;

$icons = [
    'dashboard'  => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
    'products'   => '<path d="M21 8 12 3 3 8v8l9 5 9-5z"/><path d="M3 8l9 5 9-5M12 13v8"/>',
    'inventory'  => '<path d="m12 3 9 5-9 5-9-5z"/><path d="m3 13 9 5 9-5"/>',
    'sale'       => '<circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M2 3h3l2.7 12.4a1 1 0 0 0 1 .8h8.6a1 1 0 0 0 1-.8L20 7H6"/>',
    'history'    => '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2z"/><path d="M9 8h6M9 12h6"/>',
    'returns'    => '<path d="M9 14 4 9l5-5"/><path d="M4 9h10a6 6 0 0 1 0 12h-3"/>',
    'reports'    => '<path d="M3 3v18h18"/><path d="m7 15 4-4 3 3 5-6"/>',
    'categories' => '<path d="M3 12V3h9l9 9-9 9z"/><circle cx="7.5" cy="7.5" r="1.3"/>',
    'suppliers'  => '<path d="M2 6h11v10H2zM13 9h4l3 3v4h-7"/><circle cx="6" cy="18" r="1.8"/><circle cx="17" cy="18" r="1.8"/>',
    'users'      => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.6 2.9-6 6.5-6s6.5 2.4 6.5 6"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18 14c2.2.6 3.5 2.5 3.5 6"/>',
    'backup'     => '<ellipse cx="12" cy="5.5" rx="8" ry="3"/><path d="M4 5.5v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6M4 11.5v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/>',
    'logout'     => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5M21 12H9"/>',
];
// section => [file => [label, icon, adminOnly]]
$menu = [
    'Menu' => [
        'index.php'     => ['Dashboard', 'dashboard', false],
        'products.php'  => ['Products', 'products', false],
        'inventory.php' => ['Inventory', 'inventory', false],
        'sales_new.php' => ['New Sale', 'sale', false],
        'sales.php'     => ['Sales History', 'history', false],
        'returns.php'   => ['Returns / Warranty', 'returns', false],
    ],
    'Manage' => [
        'reports.php'    => ['Reports', 'reports', false],
        'categories.php' => ['Categories', 'categories', true],
        'suppliers.php'  => ['Suppliers', 'suppliers', true],
    ],
    'Settings' => [
        'users.php'  => ['Users', 'users', true],
        'backup.php' => ['Backup', 'backup', true],
        'logout.php' => ['Logout', 'logout', false],
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($page_title ?? 'Dashboard') ?> | <?= APP_NAME ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="app">
<aside class="sidebar" id="sidebar">
    <div class="brand">
        <svg class="brand-logo" width="30" height="30" viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linejoin="round" aria-hidden="true"><path d="M32 4 56 18v28L32 60 8 46V18z"/><path d="M32 18 46 26v16L32 50 18 42V26z"/><path d="M32 4v14M56 18 46 26M8 18l10 8M32 50v10M46 42l10 4M18 42 8 46" stroke-width="1.6" opacity=".8"/></svg>
        <span>2K2R Parts</span>
    </div>
    <?php foreach ($menu as $section => $items): ?>
        <?php
        $visible = array_filter($items, fn($it) => !$it[2] || is_admin());
        if (!$visible) continue;
        ?>
        <div class="nav-label"><span><?= e($section) ?></span></div>
        <nav class="side-nav">
        <?php foreach ($visible as $file => [$label, $icon]): ?>
            <a href="<?= $file ?>" class="<?= $current === $file ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24" aria-hidden="true"><?= $icons[$icon] ?></svg><?= e($label) ?>
            </a>
        <?php endforeach; ?>
        </nav>
    <?php endforeach; ?>
    <div class="side-cta">
        <strong>Record a sale</strong>
        <p>Ring up a customer, print the receipt, and stock updates automatically.</p>
        <a class="btn" href="sales_new.php">Get Started</a>
    </div>
</aside>
<div class="scrim" id="scrim"></div>
<div class="main">
    <header class="topbar">
        <button class="burger" id="burger" aria-label="Menu"><svg viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16"/></svg></button>
        <div class="shop-name"><?= APP_NAME ?></div>
        <div class="userbox">
            <span class="avatar"><?= e(mb_strtoupper(mb_substr($_SESSION['full_name'], 0, 1))) ?></span>
            <span><?= e($_SESSION['full_name']) ?></span>
            <span class="badge"><?= e($_SESSION['role']) ?></span>
        </div>
    </header>
    <main class="container">
<?php show_flash(); ?>
