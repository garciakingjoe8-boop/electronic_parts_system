<?php
require_once __DIR__ . '/functions.php';
require_login();
verify_csrf();
$current = basename($_SERVER['PHP_SELF']);
$activeMap = ['sale_view.php' => 'sales.php', 'product_form.php' => 'products.php'];
$current = $activeMap[$current] ?? $current;

$icons = [
    'dashboard'  => '<path d="M3 11.5 12 4l9 7.5"/><path d="M5 10v10h5v-6h4v6h5V10"/>',
    'products'   => '<path d="M21 8 12 3 3 8v8l9 5 9-5z"/><path d="M3 8l9 5 9-5M12 13v8"/>',
    'categories' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
    'inventory'  => '<ellipse cx="12" cy="5.5" rx="8" ry="3"/><path d="M4 5.5v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6M4 11.5v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/>',
    'sale'       => '<circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M2 3h3l2.7 12.4a1 1 0 0 0 1 .8h8.6a1 1 0 0 0 1-.8L20 7H6"/>',
    'history'    => '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2z"/><path d="M9 8h6M9 12h6"/>',
    'returns'    => '<path d="M9 14 4 9l5-5"/><path d="M4 9h10a6 6 0 0 1 0 12h-3"/>',
    'suppliers'  => '<path d="M2 6h11v10H2zM13 9h4l3 3v4h-7"/><circle cx="6" cy="18" r="1.8"/><circle cx="17" cy="18" r="1.8"/>',
    'reports'    => '<path d="M5 20V11M11 20V4M17 20v-7M3 20h18"/>',
    'users'      => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.6 2.9-6 6.5-6s6.5 2.4 6.5 6"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18 14c2.2.6 3.5 2.5 3.5 6"/>',
    'settings'   => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
];
// file => [label, icon, adminOnly]
$menu = [
    'index.php'      => ['Dashboard', 'dashboard', false],
    'products.php'   => ['Products', 'products', false],
    'categories.php' => ['Categories', 'categories', true],
    'inventory.php'  => ['Inventory', 'inventory', false],
    'sales_new.php'  => ['New Sale', 'sale', false],
    'sales.php'      => ['Sales History', 'history', false],
    'returns.php'    => ['Returns / Warranty', 'returns', false],
    'suppliers.php'  => ['Suppliers', 'suppliers', true],
    'reports.php'    => ['Reports', 'reports', false],
    'users.php'      => ['Users', 'users', true],
    'backup.php'     => ['Backup', 'settings', true],
];
$alerts = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE quantity <= minimum_stock")->fetchColumn()
        + (int)$pdo->query("SELECT COUNT(*) FROM returns WHERE status='Pending'")->fetchColumn();
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
        <svg class="brand-logo" width="52" height="52" viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <rect x="16" y="16" width="32" height="32" rx="4"/><rect x="24" y="24" width="16" height="16" rx="2"/>
            <path d="M24 6v10M32 6v10M40 6v10M24 48v10M32 48v10M40 48v10M6 24h10M6 32h10M6 40h10M48 24h10M48 32h10M48 40h10"/>
        </svg>
        <div class="brand-text"><strong>2K2R</strong><span>Electronic Parts Shop</span><small>Inventory &amp; Sales Management</small></div>
    </div>
    <nav class="side-nav">
    <?php foreach ($menu as $file => [$label, $icon, $adminOnly]): ?>
        <?php if ($adminOnly && !is_admin()) continue; ?>
        <a href="<?= $file ?>" class="<?= $current === $file ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24" aria-hidden="true"><?= $icons[$icon] ?></svg><?= e($label) ?>
        </a>
    <?php endforeach; ?>
    </nav>
    <div class="side-art"><p>Quality Electronic Parts<br>for a Better Tomorrow</p></div>
</aside>
<div class="scrim" id="scrim"></div>
<div class="main">
    <header class="topbar">
        <button class="burger" id="burger" aria-label="Menu"><svg viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16"/></svg></button>
        <form class="search" action="products.php" method="get" role="search">
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input type="search" name="q" placeholder="Search products, codes, or categories..." aria-label="Search products">
        </form>
        <div class="top-right">
            <a class="bell" href="inventory.php?filter=low" title="Low-stock items and pending returns" aria-label="Notifications">
                <svg viewBox="0 0 24 24"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 8 3 8H3s3-1 3-8"/><path d="M10 20a2 2 0 0 0 4 0"/></svg>
                <?php if ($alerts): ?><b><?= $alerts > 99 ? '99+' : $alerts ?></b><?php endif; ?>
            </a>
            <details class="udrop">
                <summary>
                    <span class="avatar"><?= e(mb_strtoupper(mb_substr($_SESSION['full_name'], 0, 1))) ?></span>
                    <span class="who"><?= e($_SESSION['full_name']) ?><small><?= e($_SESSION['role'] === 'admin' ? 'Administrator' : 'Staff') ?></small></span>
                    <svg class="caret" viewBox="0 0 24 24"><path d="m6 9 6 6 6-6"/></svg>
                </summary>
                <div class="menu"><a href="logout.php">Logout</a></div>
            </details>
        </div>
    </header>
    <main class="container">
<?php show_flash(); ?>
