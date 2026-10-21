<?php
$page_title = 'Sale Details';
require_once __DIR__ . '/includes/header.php';

$id = (int)($_GET['id'] ?? $_POST['sale_id'] ?? 0);

// Void a sale (admin only): restores stock, keeps the record for audit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'void') {
    require_admin();
    $reason = trim($_POST['void_reason'] ?? '');
    try {
        if ($reason === '') throw new RuntimeException('Please enter the reason for voiding this sale.');
        $pdo->beginTransaction();
        $st = $pdo->prepare("SELECT status FROM sales WHERE sale_id=? FOR UPDATE");
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) throw new RuntimeException('Sale not found.');
        if ($row['status'] === 'voided') throw new RuntimeException('This sale is already voided.');
        $items = $pdo->prepare("SELECT product_id, quantity FROM sale_items WHERE sale_id=?");
        $items->execute([$id]);
        $up = $pdo->prepare("UPDATE products SET quantity = quantity + ? WHERE product_id=?");
        $mv = $pdo->prepare("INSERT INTO stock_movements (product_id,user_id,movement_type,quantity,notes) VALUES (?,?, 'IN', ?, ?)");
        foreach ($items->fetchAll() as $it) {
            $up->execute([$it['quantity'], $it['product_id']]);
            $mv->execute([$it['product_id'], $_SESSION['user_id'], $it['quantity'], "Void of Sale #$id"]);
        }
        $pdo->prepare("UPDATE sales SET status='voided', void_reason=?, voided_by=?, voided_at=NOW() WHERE sale_id=?")
            ->execute([mb_substr($reason, 0, 255), $_SESSION['user_id'], $id]);
        $pdo->commit();
        flash('success', "Sale #$id voided and stock restored.");
    } catch (RuntimeException $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', $ex->getMessage());
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log($ex->getMessage());
        flash('error', 'Database error. Nothing was changed.');
    }
    redirect("sale_view.php?id=$id");
}

$stmt = $pdo->prepare("SELECT s.*, u.full_name, vu.full_name AS voided_by_name FROM sales s
                       JOIN users u ON u.user_id=s.user_id LEFT JOIN users vu ON vu.user_id=s.voided_by WHERE s.sale_id=?");
$stmt->execute([$id]);
$sale = $stmt->fetch();
if (!$sale) { flash('error', 'Sale not found.'); redirect('sales.php'); }

$stmt = $pdo->prepare("SELECT si.*, p.product_code, p.product_name FROM sale_items si
                       JOIN products p ON p.product_id=si.product_id WHERE si.sale_id=? ORDER BY si.sale_item_id");
$stmt->execute([$id]);
$items = $stmt->fetchAll();
$voided = $sale['status'] === 'voided';
?>
<h1>Sale #<?= (int)$sale['sale_id'] ?> <?php if ($voided): ?><span class="tag tag-out">VOIDED</span><?php endif; ?></h1>
<?php if ($voided): ?>
<div class="alert alert-error">Voided on <?= e($sale['voided_at']) ?> by <?= e($sale['voided_by_name']) ?>. Reason: <?= e($sale['void_reason']) ?></div>
<?php endif; ?>
<div class="card">
    <p><strong><?= APP_NAME ?></strong><br>
       <strong>Date:</strong> <?= e($sale['sale_date']) ?><br>
       <strong>Cashier:</strong> <?= e($sale['full_name']) ?><br>
       <?php if ($sale['customer_name']): ?><strong>Customer:</strong> <?= e($sale['customer_name']) ?><?php endif; ?></p>
    <div class="table-wrap"><table>
        <tr><th>Code</th><th>Product</th><th class="num">Qty</th><th class="num">Unit price</th><th class="num">Subtotal</th></tr>
        <?php foreach ($items as $i): ?>
        <tr><td><?= e($i['product_code']) ?></td><td><?= e($i['product_name']) ?></td><td class="num"><?= (int)$i['quantity'] ?></td>
            <td class="num"><?= money($i['unit_price']) ?></td><td class="num"><?= money($i['subtotal']) ?></td></tr>
        <?php endforeach; ?>
    </table></div>
    <div class="total-box">Total: <?= money($sale['total_amount']) ?></div>
    <?php if ($sale['amount_paid'] !== null): ?>
    <p class="right">Amount received: <?= money($sale['amount_paid']) ?><br>Change: <strong><?= money($sale['amount_paid'] - $sale['total_amount']) ?></strong></p>
    <?php endif; ?>
    <p class="no-print"><button class="btn" onclick="window.print()">Print Receipt</button>
       <a class="btn btn-gray" href="sales.php">Back to history</a>
       <a class="btn" href="sales_new.php">New Sale</a></p>
</div>
<?php if (is_admin() && !$voided): ?>
<div class="card no-print">
    <h2>Void this sale</h2>
    <p class="muted">Use this to correct a recording mistake. Stock is returned and the sale is excluded from sales totals.</p>
    <form method="post" class="toolbar" onsubmit="return confirm('Void this sale and restore its stock?')">
        <?= csrf_field() ?><input type="hidden" name="action" value="void"><input type="hidden" name="sale_id" value="<?= $id ?>">
        <div class="grow"><label>Reason *</label><input name="void_reason" maxlength="255" required placeholder="e.g. Wrong quantity entered"></div>
        <button class="btn btn-danger">Void Sale</button>
    </form>
</div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
