<?php
$page_title = 'Inventory';
require_once __DIR__ . '/includes/header.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $pid = (int)($_POST['product_id'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT product_name, quantity FROM products WHERE product_id=? FOR UPDATE");
        $stmt->execute([$pid]);
        $prod = $stmt->fetch();
        if (!$prod) throw new RuntimeException('Product not found.');

        if ($action === 'stock_in') {
            $qty = (int)($_POST['quantity'] ?? 0);
            if ($qty < 1) throw new RuntimeException('Stock-in quantity must be at least 1.');
            $pdo->prepare("UPDATE products SET quantity = quantity + ? WHERE product_id=?")->execute([$qty, $pid]);
            $sup = (int)($_POST['supplier_id'] ?? 0) ?: null;
            $pdo->prepare("INSERT INTO stock_movements (product_id,user_id,movement_type,quantity,notes,supplier_id) VALUES (?,?, 'IN', ?, ?, ?)")
                ->execute([$pid, $_SESSION['user_id'], $qty, $notes ?: 'Restock / delivery', $sup]);
            flash('success', "Added $qty to {$prod['product_name']}.");
        } elseif ($action === 'adjust') {
            if (!is_admin()) throw new RuntimeException('Only the admin can adjust stock.');
            $new = $_POST['new_quantity'] ?? '';
            if (!ctype_digit((string)$new)) throw new RuntimeException('Enter a valid counted quantity (0 or more).');
            if ($notes === '') throw new RuntimeException('Please give a reason for the adjustment.');
            $delta = (int)$new - (int)$prod['quantity'];
            $pdo->prepare("UPDATE products SET quantity=? WHERE product_id=?")->execute([(int)$new, $pid]);
            $pdo->prepare("INSERT INTO stock_movements (product_id,user_id,movement_type,quantity,notes) VALUES (?,?, 'ADJUSTMENT', ?, ?)")
                ->execute([$pid, $_SESSION['user_id'], $delta, $notes]);
            flash('success', "Stock of {$prod['product_name']} adjusted to " . (int)$new . " ({$delta}).");
        } else {
            throw new RuntimeException('Unknown action.');
        }
        $pdo->commit();
    } catch (RuntimeException $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', $ex->getMessage());
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log($ex->getMessage());
        flash('error', 'Database error. Nothing was changed.');
    }
    redirect('inventory.php');
}

$q = trim($_GET['q'] ?? '');
$filter = $_GET['filter'] ?? 'all';
$sql = "SELECT p.*, c.category_name FROM products p LEFT JOIN categories c ON c.category_id=p.category_id WHERE 1=1";
$params = [];
if ($q !== '') { $sql .= " AND (p.product_name LIKE ? OR p.product_code LIKE ?)"; $params[] = "%$q%"; $params[] = "%$q%"; }
if ($filter === 'low') $sql .= " AND p.quantity <= p.minimum_stock";
$sql .= " ORDER BY (p.quantity <= p.minimum_stock) DESC, p.quantity ASC, p.product_name";
$stmt = $pdo->prepare($sql); $stmt->execute($params);
$products = $stmt->fetchAll();
$all = $pdo->query("SELECT product_id, product_code, product_name, quantity FROM products ORDER BY product_name")->fetchAll();

$suppliers = $pdo->query("SELECT * FROM suppliers ORDER BY supplier_name")->fetchAll();
$moves = $pdo->query("SELECT m.*, p.product_name, p.product_code, u.full_name, sp.supplier_name FROM stock_movements m
    JOIN products p ON p.product_id=m.product_id JOIN users u ON u.user_id=m.user_id
    LEFT JOIN suppliers sp ON sp.supplier_id=m.supplier_id
    ORDER BY m.movement_date DESC, m.movement_id DESC LIMIT 25")->fetchAll();
?>
<h1>Inventory</h1>

<div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(320px,1fr))">
    <div class="card">
        <h2>Stock-in (new delivery)</h2>
        <form method="post">
            <?= csrf_field() ?><input type="hidden" name="action" value="stock_in">
            <label>Product</label>
            <select name="product_id" required><option value="">— Select —</option>
                <?php foreach ($all as $a): ?><option value="<?= (int)$a['product_id'] ?>"><?= e($a['product_code'].' — '.$a['product_name']) ?> (<?= (int)$a['quantity'] ?>)</option><?php endforeach; ?>
            </select>
            <label>Quantity received</label><input type="number" name="quantity" min="1" required>
            <label>Supplier</label>
            <select name="supplier_id"><option value="0">— Not specified —</option>
                <?php foreach ($suppliers as $sp): ?><option value="<?= (int)$sp['supplier_id'] ?>"><?= e($sp['supplier_name']) ?></option><?php endforeach; ?>
            </select>
            <label>Notes (delivery ref, remarks)</label><input name="notes" maxlength="255">
            <p><button class="btn">Add Stock</button></p>
        </form>
    </div>
    <?php if (is_admin()): ?>
    <div class="card">
        <h2>Stock adjustment (physical count)</h2>
        <form method="post">
            <?= csrf_field() ?><input type="hidden" name="action" value="adjust">
            <label>Product</label>
            <select name="product_id" required><option value="">— Select —</option>
                <?php foreach ($all as $a): ?><option value="<?= (int)$a['product_id'] ?>"><?= e($a['product_code'].' — '.$a['product_name']) ?> (<?= (int)$a['quantity'] ?>)</option><?php endforeach; ?>
            </select>
            <label>Actual counted quantity</label><input type="number" name="new_quantity" min="0" required>
            <label>Reason *</label><input name="notes" maxlength="255" required placeholder="e.g. Damaged, miscount, lost">
            <p><button class="btn">Apply Adjustment</button></p>
        </form>
    </div>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Stock levels</h2>
    <form method="get" class="toolbar">
        <div class="grow"><label>Search</label><input name="q" value="<?= e($q) ?>" placeholder="Name or code"></div>
        <div><label>Show</label><select name="filter">
            <option value="all" <?= $filter==='all'?'selected':'' ?>>All products</option>
            <option value="low" <?= $filter==='low'?'selected':'' ?>>Low / out of stock only</option></select></div>
        <button class="btn">Filter</button>
    </form>
    <div class="table-wrap"><table>
        <tr><th>Code</th><th>Product</th><th>Category</th><th class="num">On hand</th><th class="num">Minimum</th><th>Status</th></tr>
        <?php foreach ($products as $p): ?>
        <tr><td><?= e($p['product_code']) ?></td><td><?= e($p['product_name']) ?></td><td><?= e($p['category_name'] ?? '—') ?></td>
            <td class="num"><?= (int)$p['quantity'] ?></td><td class="num"><?= (int)$p['minimum_stock'] ?></td>
            <td><?php if ($p['quantity'] <= 0): ?><span class="tag tag-out">Out of stock</span>
                <?php elseif ($p['quantity'] <= $p['minimum_stock']): ?><span class="tag tag-low">Restock</span>
                <?php else: ?><span class="tag tag-ok">OK</span><?php endif; ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$products): ?><tr><td colspan="6" class="muted">Nothing to show.</td></tr><?php endif; ?>
    </table></div>
</div>

<div class="card">
    <h2>Recent stock movements</h2>
    <div class="table-wrap"><table>
        <tr><th>Date</th><th>Product</th><th>Type</th><th class="num">Qty</th><th>Notes</th><th>By</th></tr>
        <?php foreach ($moves as $m): $cls = ['IN'=>'tag-in','OUT'=>'tag-outm','ADJUSTMENT'=>'tag-adj'][$m['movement_type']]; ?>
        <tr><td><?= e($m['movement_date']) ?></td><td><?= e($m['product_code'].' — '.$m['product_name']) ?></td>
            <td><span class="tag <?= $cls ?>"><?= e($m['movement_type']) ?></span></td>
            <td class="num"><?= ($m['movement_type']==='ADJUSTMENT' && $m['quantity']>0 ? '+' : '') . (int)$m['quantity'] ?></td>
            <td><?= e($m['notes']) ?><?= $m['supplier_name'] ? ' <span class="muted">(' . e($m['supplier_name']) . ')</span>' : '' ?></td><td><?= e($m['full_name']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$moves): ?><tr><td colspan="6" class="muted">No movements yet.</td></tr><?php endif; ?>
    </table></div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
