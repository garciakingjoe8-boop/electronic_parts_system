<?php
$page_title = 'Returns / Warranty';
require_once __DIR__ . '/includes/header.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'log') {
            $pid = (int)($_POST['product_id'] ?? 0);
            $qty = (int)($_POST['quantity'] ?? 0);
            $type = in_array($_POST['return_type'] ?? '', ['Return','Replacement','Warranty Check'], true) ? $_POST['return_type'] : 'Return';
            $concern = trim($_POST['concern'] ?? '');
            $customer = trim($_POST['customer_name'] ?? '');
            $saleId = (int)($_POST['sale_id'] ?? 0) ?: null;
            if ($pid < 1 || $qty < 1 || $concern === '') throw new RuntimeException('Product, quantity, and the customer\'s concern are required.');
            if ($saleId) {
                $chk = $pdo->prepare("SELECT 1 FROM sale_items si JOIN sales s ON s.sale_id=si.sale_id WHERE si.sale_id=? AND si.product_id=? AND s.status='completed'");
                $chk->execute([$saleId, $pid]);
                if (!$chk->fetchColumn()) throw new RuntimeException("Sale #$saleId does not contain that product (or it was voided). Check the proof of purchase.");
            }
            $pdo->prepare("INSERT INTO returns (sale_id, product_id, user_id, customer_name, quantity, return_type, concern) VALUES (?,?,?,?,?,?,?)")
                ->execute([$saleId, $pid, $_SESSION['user_id'], $customer ?: null, $qty, $type, $concern]);
            flash('success', 'Return / warranty concern recorded as Pending.');
        } elseif ($action === 'resolve') {
            $rid = (int)($_POST['return_id'] ?? 0);
            $decision = ($_POST['decision'] ?? '') === 'Rejected' ? 'Rejected' : 'Resolved';
            $effect = $_POST['stock_effect'] ?? 'none';
            $note = trim($_POST['resolution'] ?? '');
            if ($note === '') throw new RuntimeException('Please describe the action taken.');
            $pdo->beginTransaction();
            $st = $pdo->prepare("SELECT * FROM returns WHERE return_id=? FOR UPDATE");
            $st->execute([$rid]);
            $r = $st->fetch();
            if (!$r) throw new RuntimeException('Record not found.');
            if ($r['status'] !== 'Pending') throw new RuntimeException('This record was already closed.');
            if ($decision === 'Resolved' && $effect !== 'none') {
                $ps = $pdo->prepare("SELECT quantity FROM products WHERE product_id=? FOR UPDATE");
                $ps->execute([$r['product_id']]);
                $have = (int)$ps->fetchColumn();
                if ($effect === 'restock') {
                    $pdo->prepare("UPDATE products SET quantity=quantity+? WHERE product_id=?")->execute([$r['quantity'], $r['product_id']]);
                    $pdo->prepare("INSERT INTO stock_movements (product_id,user_id,movement_type,quantity,notes) VALUES (?,?, 'IN', ?, ?)")
                        ->execute([$r['product_id'], $_SESSION['user_id'], $r['quantity'], "Returned item (Return #$rid) back to stock"]);
                } elseif ($effect === 'replace') {
                    if ($have < $r['quantity']) throw new RuntimeException("Not enough stock to give a replacement (available: $have).");
                    $pdo->prepare("UPDATE products SET quantity=quantity-? WHERE product_id=?")->execute([$r['quantity'], $r['product_id']]);
                    $pdo->prepare("INSERT INTO stock_movements (product_id,user_id,movement_type,quantity,notes) VALUES (?,?, 'OUT', ?, ?)")
                        ->execute([$r['product_id'], $_SESSION['user_id'], $r['quantity'], "Replacement given (Return #$rid)"]);
                }
            }
            $pdo->prepare("UPDATE returns SET status=?, resolution=?, resolved_at=NOW() WHERE return_id=?")->execute([$decision, mb_substr($note, 0, 255), $rid]);
            $pdo->commit();
            flash('success', "Record #$rid marked $decision.");
        }
    } catch (RuntimeException $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', $ex->getMessage());
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log($ex->getMessage());
        flash('error', 'Database error. Nothing was changed.');
    }
    redirect('returns.php');
}

$filter = $_GET['status'] ?? 'Pending';
$sql = "SELECT r.*, p.product_code, p.product_name, u.full_name FROM returns r
        JOIN products p ON p.product_id=r.product_id JOIN users u ON u.user_id=r.user_id";
$params = [];
if (in_array($filter, ['Pending','Resolved','Rejected'], true)) { $sql .= " WHERE r.status=?"; $params[] = $filter; }
$sql .= " ORDER BY r.created_at DESC LIMIT 200";
$stmt = $pdo->prepare($sql); $stmt->execute($params);
$rows = $stmt->fetchAll();
$all = $pdo->query("SELECT product_id, product_code, product_name FROM products ORDER BY product_name")->fetchAll();
?>
<h1>Returns / Warranty</h1>
<div class="card">
    <h2>Record a return or warranty concern</h2>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="log">
        <div class="form-grid">
            <div><label>Product *</label><select name="product_id" required><option value="">— Select —</option>
                <?php foreach ($all as $a): ?><option value="<?= (int)$a['product_id'] ?>"><?= e($a['product_code'].' — '.$a['product_name']) ?></option><?php endforeach; ?></select></div>
            <div><label>Quantity *</label><input type="number" name="quantity" min="1" value="1" required></div>
            <div><label>Type</label><select name="return_type"><option>Return</option><option>Replacement</option><option>Warranty Check</option></select></div>
            <div><label>Sale # (proof of purchase)</label><input type="number" name="sale_id" min="1" placeholder="optional"></div>
            <div><label>Customer name</label><input name="customer_name" maxlength="100"></div>
        </div>
        <label>Customer's concern *</label>
        <textarea name="concern" rows="2" required placeholder="e.g. Capacitor is defective / does not hold charge"></textarea>
        <p><button class="btn">Record</button></p>
    </form>
</div>

<div class="card">
    <form method="get" class="toolbar">
        <div><label>Show</label><select name="status" onchange="this.form.submit()">
            <?php foreach (['Pending','Resolved','Rejected','All'] as $o): ?><option <?= $filter === $o ? 'selected' : '' ?>><?= $o ?></option><?php endforeach; ?></select></div>
    </form>
    <div class="table-wrap"><table>
        <tr><th>#</th><th>Date</th><th>Product</th><th class="num">Qty</th><th>Type</th><th>Customer / Sale</th><th>Concern</th><th>Status</th></tr>
        <?php foreach ($rows as $r): ?>
        <tr>
            <td><?= (int)$r['return_id'] ?></td><td><?= e($r['created_at']) ?></td>
            <td><?= e($r['product_code'].' — '.$r['product_name']) ?></td><td class="num"><?= (int)$r['quantity'] ?></td>
            <td><?= e($r['return_type']) ?></td>
            <td><?= e($r['customer_name'] ?? '—') ?><?= $r['sale_id'] ? ' · <a href="sale_view.php?id='.(int)$r['sale_id'].'">Sale #'.(int)$r['sale_id'].'</a>' : '' ?></td>
            <td><?= e($r['concern']) ?></td>
            <td>
            <?php if ($r['status'] === 'Pending'): ?>
                <span class="tag tag-low">Pending</span>
                <form method="post" style="margin-top:8px;min-width:220px"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="resolve"><input type="hidden" name="return_id" value="<?= (int)$r['return_id'] ?>">
                    <select name="decision"><option>Resolved</option><option>Rejected</option></select>
                    <select name="stock_effect" style="margin-top:4px">
                        <option value="none">No stock change</option>
                        <option value="restock">Item goes back to stock (+<?= (int)$r['quantity'] ?>)</option>
                        <option value="replace">Give replacement from stock (−<?= (int)$r['quantity'] ?>)</option>
                    </select>
                    <input name="resolution" placeholder="Action taken *" required maxlength="255" style="margin-top:4px">
                    <button class="btn btn-sm" style="margin-top:4px">Close</button>
                </form>
            <?php else: ?>
                <span class="tag <?= $r['status'] === 'Resolved' ? 'tag-ok' : 'tag-out' ?>"><?= e($r['status']) ?></span>
                <div class="muted"><?= e($r['resolution']) ?></div>
            <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="8" class="muted">No records.</td></tr><?php endif; ?>
    </table></div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
