<?php
$page_title = 'Sales History';
require_once __DIR__ . '/includes/header.php';

$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';
$q = trim($_GET['q'] ?? '');
$valid = fn($d) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);

$sql = "SELECT s.sale_id, s.sale_date, s.total_amount, s.status, s.customer_name, u.full_name,
               (SELECT COALESCE(SUM(quantity),0) FROM sale_items WHERE sale_id=s.sale_id) AS items
        FROM sales s JOIN users u ON u.user_id=s.user_id WHERE 1=1";
$params = [];
if ($valid($from)) { $sql .= " AND DATE(s.sale_date) >= ?"; $params[] = $from; }
if ($valid($to))   { $sql .= " AND DATE(s.sale_date) <= ?"; $params[] = $to; }
if ($q !== '') {
    if (ctype_digit(ltrim($q, '#'))) { $sql .= " AND s.sale_id = ?"; $params[] = (int)ltrim($q, '#'); }
    else {
        $sql .= " AND EXISTS (SELECT 1 FROM sale_items si JOIN products p ON p.product_id=si.product_id
                  WHERE si.sale_id=s.sale_id AND (p.product_name LIKE ? OR p.product_code LIKE ?))";
        $params[] = "%$q%"; $params[] = "%$q%";
    }
}
$sql .= " ORDER BY s.sale_date DESC, s.sale_id DESC LIMIT 300";
$stmt = $pdo->prepare($sql); $stmt->execute($params);
$sales = $stmt->fetchAll();
$sum = array_sum(array_map(fn($x) => $x['status'] === 'completed' ? $x['total_amount'] : 0, $sales));
?>
<h1>Sales History</h1>
<div class="card">
    <form method="get" class="toolbar">
        <div><label>From</label><input type="date" name="from" value="<?= e($from) ?>"></div>
        <div><label>To</label><input type="date" name="to" value="<?= e($to) ?>"></div>
        <div class="grow"><label>Sale # or product</label><input name="q" value="<?= e($q) ?>" placeholder="e.g. 12 or capacitor"></div>
        <button class="btn">Search</button><a class="btn btn-gray" href="sales.php">Reset</a>
    </form>
    <div class="table-wrap"><table>
        <tr><th>Sale #</th><th>Date</th><th>Customer</th><th>Cashier</th><th class="num">Items</th><th class="num">Total</th><th></th></tr>
        <?php foreach ($sales as $s): ?>
        <tr><td>#<?= (int)$s['sale_id'] ?></td><td><?= e($s['sale_date']) ?></td><td><?= e($s['customer_name'] ?? '—') ?></td><td><?= e($s['full_name']) ?></td>
            <td class="num"><?= (int)$s['items'] ?></td><td class="num"><?= money($s['total_amount']) ?></td>
            <td><?php if ($s['status'] === 'voided'): ?><span class="tag tag-out">Voided</span> <?php endif; ?><a class="btn btn-sm" href="sale_view.php?id=<?= (int)$s['sale_id'] ?>">Details</a></td></tr>
        <?php endforeach; ?>
        <?php if (!$sales): ?><tr><td colspan="7" class="muted">No sales found.</td></tr><?php endif; ?>
    </table></div>
    <p><strong><?= count($sales) ?></strong> sale(s) · Total (excluding voided) <strong><?= money($sum) ?></strong></p>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
