<?php
$page_title = 'Reports';
require_once __DIR__ . '/includes/header.php';

$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $to = date('Y-m-d');

$stmt = $pdo->prepare("SELECT COUNT(*) c, COALESCE(SUM(total_amount),0) t FROM sales WHERE status='completed' AND DATE(sale_date) BETWEEN ? AND ?");
$stmt->execute([$from, $to]);
$summary = $stmt->fetch();

$stmt = $pdo->prepare("SELECT DATE(sale_date) d, COUNT(*) n, SUM(total_amount) t FROM sales
                       WHERE status='completed' AND DATE(sale_date) BETWEEN ? AND ? GROUP BY DATE(sale_date) ORDER BY d DESC");
$stmt->execute([$from, $to]);
$daily = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT p.product_code, p.product_name, SUM(si.quantity) qty, SUM(si.subtotal) rev
                       FROM sale_items si JOIN sales s ON s.sale_id=si.sale_id JOIN products p ON p.product_id=si.product_id
                       WHERE s.status='completed' AND DATE(s.sale_date) BETWEEN ? AND ? GROUP BY p.product_id ORDER BY qty DESC LIMIT 10");
$stmt->execute([$from, $to]);
$top = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT p.product_code, p.product_name, p.quantity FROM products p
                       WHERE p.quantity > 0 AND NOT EXISTS (
                         SELECT 1 FROM sale_items si JOIN sales s ON s.sale_id=si.sale_id
                         WHERE si.product_id=p.product_id AND s.status='completed' AND DATE(s.sale_date) BETWEEN ? AND ?)
                       ORDER BY p.quantity DESC LIMIT 15");
$stmt->execute([$from, $to]);
$slow = $stmt->fetchAll();

$inv = $pdo->query("SELECT p.*, c.category_name FROM products p LEFT JOIN categories c ON c.category_id=p.category_id
                    ORDER BY c.category_name, p.product_name")->fetchAll();
$restock = array_filter($inv, fn($p) => $p['quantity'] <= $p['minimum_stock']);
$invValue = array_sum(array_map(fn($p) => $p['quantity'] * $p['unit_price'], $inv));
?>
<h1>Reports</h1>
<div class="card no-print">
    <form method="get" class="toolbar">
        <div><label>From</label><input type="date" name="from" value="<?= e($from) ?>"></div>
        <div><label>To</label><input type="date" name="to" value="<?= e($to) ?>"></div>
        <button class="btn">Generate</button>
        <button type="button" class="btn btn-gray" onclick="window.print()">Print</button>
        <a class="btn btn-gray" href="export.php?type=sales&from=<?= e($from) ?>&to=<?= e($to) ?>">Export sales (CSV)</a>
        <a class="btn btn-gray" href="export.php?type=products">Export products (CSV)</a>
        <?php if (is_admin()): ?><a class="btn" href="backup.php">Download full backup (.sql)</a><?php endif; ?>
    </form>
</div>

<div class="grid">
    <div class="stat"><div class="label">Transactions (<?= e($from) ?> to <?= e($to) ?>)</div><div class="value"><?= (int)$summary['c'] ?></div></div>
    <div class="stat"><div class="label">Total sales</div><div class="value"><?= money($summary['t']) ?></div></div>
    <div class="stat"><div class="label">Inventory value (at price)</div><div class="value"><?= money($invValue) ?></div></div>
</div>

<div class="card"><h2>Daily sales</h2>
<div class="table-wrap"><table>
    <tr><th>Date</th><th class="num">Transactions</th><th class="num">Total</th></tr>
    <?php foreach ($daily as $d): ?><tr><td><?= e($d['d']) ?></td><td class="num"><?= (int)$d['n'] ?></td><td class="num"><?= money($d['t']) ?></td></tr><?php endforeach; ?>
    <?php if (!$daily): ?><tr><td colspan="3" class="muted">No sales in this period.</td></tr><?php endif; ?>
</table></div></div>

<div class="card"><h2>Best-selling products</h2>
<div class="table-wrap"><table>
    <tr><th>Code</th><th>Product</th><th class="num">Units sold</th><th class="num">Revenue</th></tr>
    <?php foreach ($top as $t): ?><tr><td><?= e($t['product_code']) ?></td><td><?= e($t['product_name']) ?></td><td class="num"><?= (int)$t['qty'] ?></td><td class="num"><?= money($t['rev']) ?></td></tr><?php endforeach; ?>
    <?php if (!$top): ?><tr><td colspan="4" class="muted">No sales in this period.</td></tr><?php endif; ?>
</table></div></div>

<div class="card"><h2>Slow-moving products (in stock, no sales in this period)</h2>
<div class="table-wrap"><table>
    <tr><th>Code</th><th>Product</th><th class="num">On hand</th></tr>
    <?php foreach ($slow as $t): ?><tr><td><?= e($t['product_code']) ?></td><td><?= e($t['product_name']) ?></td><td class="num"><?= (int)$t['quantity'] ?></td></tr><?php endforeach; ?>
    <?php if (!$slow): ?><tr><td colspan="3" class="muted">Every stocked product had a sale in this period.</td></tr><?php endif; ?>
</table></div></div>

<div class="card"><h2>Restock list</h2>
<div class="table-wrap"><table>
    <tr><th>Code</th><th>Product</th><th class="num">On hand</th><th class="num">Minimum</th><th class="num">Suggested order</th></tr>
    <?php foreach ($restock as $p): ?><tr><td><?= e($p['product_code']) ?></td><td><?= e($p['product_name']) ?></td>
        <td class="num"><?= (int)$p['quantity'] ?></td><td class="num"><?= (int)$p['minimum_stock'] ?></td>
        <td class="num"><?= max(0, $p['minimum_stock'] * 2 - $p['quantity']) ?></td></tr><?php endforeach; ?>
    <?php if (!$restock): ?><tr><td colspan="5" class="muted">All products are above their minimum stock.</td></tr><?php endif; ?>
</table></div></div>

<div class="card"><h2>Full inventory summary</h2>
<div class="table-wrap"><table>
    <tr><th>Category</th><th>Code</th><th>Product</th><th class="num">Qty</th><th class="num">Price</th><th class="num">Value</th></tr>
    <?php foreach ($inv as $p): ?><tr><td><?= e($p['category_name'] ?? '—') ?></td><td><?= e($p['product_code']) ?></td><td><?= e($p['product_name']) ?></td>
        <td class="num"><?= (int)$p['quantity'] ?></td><td class="num"><?= money($p['unit_price']) ?></td><td class="num"><?= money($p['quantity']*$p['unit_price']) ?></td></tr><?php endforeach; ?>
    <?php if (!$inv): ?><tr><td colspan="6" class="muted">No products yet.</td></tr><?php endif; ?>
</table></div></div>
<?php require __DIR__ . '/includes/footer.php'; ?>
