<?php
$page_title = 'Inventory';
require_once __DIR__ . '/includes/header.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $pid = (int)($_POST['product_id'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    $addedId = 0;

    try {
        if ($pid < 1) throw new RuntimeException('Please choose a product from the list first.');
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
            $newQty = (int)$prod['quantity'] + $qty;
            flash('success', "Stock added: +$qty × {$prod['product_name']}. Stock went from {$prod['quantity']} to $newQty.");
            $addedId = $pid;
        } elseif ($action === 'adjust') {
            if (!is_admin()) throw new RuntimeException('Only the admin can adjust stock.');
            $new = $_POST['new_quantity'] ?? '';
            if (!ctype_digit((string)$new)) throw new RuntimeException('Enter a valid counted quantity (0 or more).');
            if ($notes === '') throw new RuntimeException('Please give a reason for the adjustment.');
            $delta = (int)$new - (int)$prod['quantity'];
            $pdo->prepare("UPDATE products SET quantity=? WHERE product_id=?")->execute([(int)$new, $pid]);
            $pdo->prepare("INSERT INTO stock_movements (product_id,user_id,movement_type,quantity,notes) VALUES (?,?, 'ADJUSTMENT', ?, ?)")
                ->execute([$pid, $_SESSION['user_id'], $delta, $notes]);
            flash('success', "Stock of {$prod['product_name']} adjusted from {$prod['quantity']} to " . (int)$new . " ({$delta}).");
            $addedId = $pid;
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
    redirect($addedId ? 'products.php?updated=' . $addedId : 'inventory.php');
}

$filter = $_GET['filter'] ?? 'all';
$products = $pdo->query("SELECT p.*, c.category_name,
        (SELECT MAX(m.movement_date) FROM stock_movements m WHERE m.product_id=p.product_id AND m.movement_type='IN') AS last_in
    FROM products p LEFT JOIN categories c ON c.category_id=p.category_id
    ORDER BY (p.quantity <= p.minimum_stock) DESC, p.quantity ASC, p.product_name")->fetchAll();
$cats = $pdo->query("SELECT category_id, category_name FROM categories ORDER BY category_name")->fetchAll();
$suppliers = $pdo->query("SELECT * FROM suppliers ORDER BY supplier_name")->fetchAll();
$moves = $pdo->query("SELECT m.*, p.product_name, p.product_code, u.full_name, sp.supplier_name FROM stock_movements m
    JOIN products p ON p.product_id=m.product_id JOIN users u ON u.user_id=m.user_id
    LEFT JOIN suppliers sp ON sp.supplier_id=m.supplier_id
    ORDER BY m.movement_date DESC, m.movement_id DESC LIMIT 25")->fetchAll();

$js = [];
foreach ($products as $p) {
    $js[] = ['id' => (int)$p['product_id'], 'code' => $p['product_code'], 'name' => $p['product_name'], 'cat' => $p['category_name'],
             'qty' => (int)$p['quantity'], 'min' => (int)$p['minimum_stock'],
             's' => mb_strtolower($p['product_code'] . ' ' . $p['product_name'] . ' ' . ($p['category_name'] ?? '') . ' ' . ($p['description'] ?? ''))];
}
function st_of(array $p): string { return $p['quantity'] <= 0 ? 'out' : ($p['quantity'] <= $p['minimum_stock'] ? 'low' : 'ok'); }
$statusPre = $filter === 'low' ? 'restock' : 'all';
?>
<h1>Inventory</h1>
<p class="page-sub">Find a product, add stock, and watch the quantity update.</p>

<div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(340px,1fr))">
    <div class="card" id="stockin">
        <h2>➕ Stock-in (new delivery)</h2>
        <form method="post" autocomplete="off">
            <?= csrf_field() ?><input type="hidden" name="action" value="stock_in">
            <label>1. Find the product</label>
            <div class="picker" id="pickerIn">
                <input class="pk-input" type="text" placeholder="Type a name or code, e.g. 10k, capacitor, R-10K…">
                <input type="hidden" name="product_id" class="pk-id">
                <div class="pk-list" hidden></div>
                <div class="pk-card" hidden></div>
                <div class="pk-err" hidden>Please pick a product from the list.</div>
            </div>
            <label>2. Quantity received</label>
            <input type="number" name="quantity" id="qtyIn" min="1" required placeholder="How many units arrived?">
            <div class="preview" id="prevIn" hidden></div>
            <label>Supplier (optional)</label>
            <select name="supplier_id"><option value="0">— Not specified —</option>
                <?php foreach ($suppliers as $sp): ?><option value="<?= (int)$sp['supplier_id'] ?>"><?= e($sp['supplier_name']) ?></option><?php endforeach; ?>
            </select>
            <label>Notes (delivery ref, remarks)</label><input name="notes" maxlength="255">
            <p><button class="btn">Add Stock</button></p>
        </form>
    </div>

    <?php if (is_admin()): ?>
    <div class="card">
        <h2>🧮 Stock adjustment (physical count)</h2>
        <form method="post" autocomplete="off">
            <?= csrf_field() ?><input type="hidden" name="action" value="adjust">
            <label>1. Find the product</label>
            <div class="picker" id="pickerAdj">
                <input class="pk-input" type="text" placeholder="Type a name or code…">
                <input type="hidden" name="product_id" class="pk-id">
                <div class="pk-list" hidden></div>
                <div class="pk-card" hidden></div>
                <div class="pk-err" hidden>Please pick a product from the list.</div>
            </div>
            <label>2. Actual counted quantity</label>
            <input type="number" name="new_quantity" id="qtyAdj" min="0" required>
            <div class="preview" id="prevAdj" hidden></div>
            <label>Reason *</label><input name="notes" maxlength="255" required placeholder="e.g. Damaged, miscount, lost">
            <p><button class="btn">Apply Adjustment</button></p>
        </form>
    </div>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Stock levels</h2>
    <div class="finder">
        <div class="grow"><label for="invSearch">Search products</label>
            <svg class="sicon" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input id="invSearch" class="big" data-live="#invTable" data-count="#invCount" placeholder="Type to find a product — name, code, category…" autocomplete="off"><kbd>/</kbd></div>
        <div><label>Category</label>
            <select data-filter-for="#invTable" data-attr="cat"><option value="all">All categories</option>
                <?php foreach ($cats as $c): ?><option value="<?= (int)$c['category_id'] ?>"><?= e($c['category_name']) ?></option><?php endforeach; ?></select></div>
        <div><label>Status</label>
            <select data-filter-for="#invTable" data-attr="st">
                <option value="all">All</option>
                <option value="restock" <?= $statusPre === 'restock' ? 'selected' : '' ?>>Low or out of stock</option>
                <option value="out">Out of stock</option><option value="ok">In stock</option></select></div>
        <a href="#" class="btn btn-gray" data-clear-for="#invTable">Clear</a>
    </div>
    <p class="found">Showing <b id="invCount"><?= count($products) ?></b> of <?= count($products) ?> products</p>
    <div class="table-wrap"><table id="invTable">
        <tr><th>Code</th><th>Product</th><th>Category</th><th class="num">On hand</th><th class="num">Minimum</th><th>Status</th><th>Last stock-in</th><th></th></tr>
        <?php foreach ($products as $p): $st = st_of($p); ?>
        <tr id="row-<?= (int)$p['product_id'] ?>" data-s="<?= e(mb_strtolower($p['product_code'] . ' ' . $p['product_name'] . ' ' . ($p['category_name'] ?? '') . ' ' . ($p['description'] ?? ''))) ?>"
            data-cat="<?= (int)$p['category_id'] ?>" data-st="<?= $st ?>">
            <td class="hl-t"><?= e($p['product_code']) ?></td>
            <td><strong class="hl-t"><?= e($p['product_name']) ?></strong></td>
            <td class="hl-t"><?= e($p['category_name'] ?? '—') ?></td>
            <td class="num"><strong><?= (int)$p['quantity'] ?></strong></td><td class="num"><?= (int)$p['minimum_stock'] ?></td>
            <td><?php if ($st === 'out'): ?><span class="tag tag-out">Out of stock</span>
                <?php elseif ($st === 'low'): ?><span class="tag tag-low">Restock</span>
                <?php else: ?><span class="tag tag-ok">OK</span><?php endif; ?></td>
            <td class="muted"><?= $p['last_in'] ? e(date('M j, Y', strtotime($p['last_in']))) : '—' ?></td>
            <td><button type="button" class="btn-add" data-add="<?= (int)$p['product_id'] ?>">+ Stock</button></td>
        </tr>
        <?php endforeach; ?>
        <tr class="no-match" hidden><td colspan="8"><?= $products ? 'No product matches your search or filters.' : 'No products yet.' ?></td></tr>
    </table></div>
</div>

<div class="card">
    <h2>Recent stock movements</h2>
    <div class="table-wrap"><table>
        <tr><th>Date</th><th>Product</th><th>Type</th><th class="num">Qty</th><th>Notes</th><th>By</th></tr>
        <?php foreach ($moves as $m): $cls = ['IN' => 'tag-in', 'OUT' => 'tag-outm', 'ADJUSTMENT' => 'tag-adj'][$m['movement_type']]; ?>
        <tr><td><?= e($m['movement_date']) ?></td><td><?= e($m['product_code'] . ' — ' . $m['product_name']) ?></td>
            <td><span class="tag <?= $cls ?>"><?= e($m['movement_type']) ?></span></td>
            <td class="num"><?= ($m['movement_type'] === 'ADJUSTMENT' && $m['quantity'] > 0 ? '+' : '') . (int)$m['quantity'] ?></td>
            <td><?= e($m['notes']) ?><?= $m['supplier_name'] ? ' <span class="muted">(' . e($m['supplier_name']) . ')</span>' : '' ?></td><td><?= e($m['full_name']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$moves): ?><tr><td colspan="6" class="muted">No movements yet.</td></tr><?php endif; ?>
    </table></div>
</div>

<script>window.PRODUCTS = <?= json_encode($js, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script src="assets/search.js"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
