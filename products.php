<?php
$page_title = 'Products';
require_once __DIR__ . '/includes/header.php';

// Delete (admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    require_admin();
    $id = (int)$_POST['product_id'];
    $used = $pdo->prepare("SELECT (SELECT COUNT(*) FROM sale_items WHERE product_id=?) + (SELECT COUNT(*) FROM stock_movements WHERE product_id=?)");
    $used->execute([$id, $id]);
    if ((int)$used->fetchColumn() > 0) {
        flash('error', 'This product has sales or stock history and cannot be deleted. Set its stock to 0 instead.');
    } else {
        $pdo->prepare("DELETE FROM products WHERE product_id=?")->execute([$id]);
        flash('success', 'Product deleted.');
    }
    redirect('products.php');
}

$q = trim($_GET['q'] ?? '');
$cat = (int)($_GET['category'] ?? 0);
$sql = "SELECT p.*, c.category_name,
        (SELECT m.quantity FROM stock_movements m WHERE m.product_id=p.product_id AND m.movement_type='IN' AND m.movement_date >= (NOW() - INTERVAL 3 DAY)
            ORDER BY m.movement_date DESC, m.movement_id DESC LIMIT 1) AS recent_qty,
        (SELECT MAX(m.movement_date) FROM stock_movements m WHERE m.product_id=p.product_id AND m.movement_type='IN' AND m.movement_date >= (NOW() - INTERVAL 3 DAY)) AS recent_date
        FROM products p LEFT JOIN categories c ON c.category_id = p.category_id WHERE 1=1";
$params = [];
if ($q !== '') {
    $sql .= " AND (p.product_name LIKE ? OR p.product_code LIKE ? OR p.description LIKE ? OR c.category_name LIKE ?)";
    $like = "%$q%"; array_push($params, $like, $like, $like, $like);
}
if ($cat > 0) { $sql .= " AND p.category_id = ?"; $params[] = $cat; }
$sql .= " ORDER BY p.product_name";
$stmt = $pdo->prepare($sql); $stmt->execute($params);
$products = $stmt->fetchAll();
$cats = $pdo->query("SELECT * FROM categories ORDER BY category_name")->fetchAll();

// The product that was just restocked / adjusted: show it first and explain what changed
$updatedId = (int)($_GET['updated'] ?? 0);
$updated = null; $lastMove = null;
if ($updatedId) {
    foreach ($products as $k => $row) {
        if ((int)$row['product_id'] === $updatedId) { $updated = $row; unset($products[$k]); array_unshift($products, $row); $products = array_values($products); break; }
    }
    if ($updated) {
        $mv = $pdo->prepare("SELECT movement_type, quantity, notes, movement_date FROM stock_movements WHERE product_id=? ORDER BY movement_date DESC, movement_id DESC LIMIT 1");
        $mv->execute([$updatedId]);
        $lastMove = $mv->fetch();
    }
}

function stock_tag(array $p): string {
    if ($p['quantity'] <= 0) return '<span class="tag tag-out">Out of stock</span>';
    if ($p['quantity'] <= $p['minimum_stock']) return '<span class="tag tag-low">Low</span>';
    return '<span class="tag tag-ok">In stock</span>';
}
?>
<h1>Products</h1>
<?php if ($updated): ?>
<div class="updated-card">
    <div class="uc-ico">✓</div>
    <div class="uc-main">
        <strong>Stock updated: <?= e($updated['product_name']) ?></strong>
        <span><?= e($updated['product_code']) ?> · <?= e($updated['category_name'] ?? 'No category') ?>
            <?php if ($lastMove): ?> · <?= $lastMove['movement_type'] === 'IN' ? '+' . (int)$lastMove['quantity'] . ' received' : ($lastMove['movement_type'] === 'ADJUSTMENT' ? 'adjusted by ' . ((int)$lastMove['quantity'] > 0 ? '+' : '') . (int)$lastMove['quantity'] : '') ?><?php endif; ?></span>
    </div>
    <div class="uc-num"><b><?= (int)$updated['quantity'] ?></b><span>now on hand</span></div>
    <div class="uc-actions">
        <a class="btn btn-sm" href="inventory.php?product=<?= (int)$updated['product_id'] ?>#stockin">+ Add more stock</a>
        <?php if (is_admin()): ?><a class="btn btn-sm btn-gray" href="product_form.php?id=<?= (int)$updated['product_id'] ?>">Edit product</a><?php endif; ?>
    </div>
</div>
<?php endif; ?>
<div class="card">
    <form method="get" class="toolbar">
        <div class="grow" style="position:relative"><label for="prodSearch">Search by name, code, category, or specification</label>
            <svg class="sicon" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input id="prodSearch" class="big" name="q" value="<?= e($q) ?>" data-live="#prodTable" data-count="#prodCount" autocomplete="off"
                   placeholder="Start typing — results appear instantly (e.g. 10k resistor, 2N2222, 470uF)"><kbd style="position:absolute;right:12px;bottom:12px;font-size:.72rem;color:var(--muted);border:1px solid var(--line);border-radius:6px;padding:0 7px">/</kbd></div>
        <div><label>Category</label><select name="category" data-filter-for="#prodTable" data-attr="cat">
            <option value="0">All</option>
            <?php foreach ($cats as $c): ?>
            <option value="<?= (int)$c['category_id'] ?>" <?= $cat === (int)$c['category_id'] ? 'selected' : '' ?>><?= e($c['category_name']) ?></option>
            <?php endforeach; ?></select></div>
        <button class="btn">Search</button>
        <a class="btn btn-gray" href="products.php" data-clear-for="#prodTable">Reset</a>
        <?php if (is_admin()): ?><a class="btn" href="product_form.php">+ Add Product</a><?php endif; ?>
    </form>
    <div class="table-wrap"><table id="prodTable">
        <tr><th>Code</th><th>Product</th><th>Category</th><th class="num">Price</th><th class="num">Stock</th><th>Status</th><?php if (is_admin()): ?><th>Actions</th><?php endif; ?></tr>
        <?php foreach ($products as $p): ?>
        <tr id="row-<?= (int)$p['product_id'] ?>" class="<?= (int)$p['product_id'] === $updatedId ? 'just flash' : '' ?>" data-s="<?= e(mb_strtolower($p['product_code'] . ' ' . $p['product_name'] . ' ' . ($p['category_name'] ?? '') . ' ' . ($p['description'] ?? ''))) ?>" data-cat="<?= (int)$p['category_id'] ?>">
            <td class="hl-t"><?= e($p['product_code']) ?></td>
            <td><strong class="hl-t"><?= e($p['product_name']) ?></strong>
                <?php if ($p['description']): ?><div class="muted"><?= e(mb_strimwidth($p['description'], 0, 70, '…')) ?></div><?php endif; ?></td>
            <td class="hl-t"><?= e($p['category_name'] ?? '—') ?></td>
            <td class="num"><?= money($p['unit_price']) ?></td>
            <td class="num"><strong><?= (int)$p['quantity'] ?></strong>
                <?php if ($p['recent_qty']): ?><div><span class="tag tag-in" title="Restocked <?= e($p['recent_date']) ?>">+<?= (int)$p['recent_qty'] ?> new</span></div>
                <div class="muted" style="font-size:.72rem">Restocked <?= e(date('M j', strtotime($p['recent_date']))) ?></div><?php endif; ?></td>
            <td><?= stock_tag($p) ?></td>
            <?php if (is_admin()): ?>
            <td>
                <a class="btn btn-sm" href="product_form.php?id=<?= (int)$p['product_id'] ?>">Edit</a>
                <form method="post" class="inline" onsubmit="return confirm('Delete this product?')">
                    <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="product_id" value="<?= (int)$p['product_id'] ?>">
                    <button class="btn btn-sm btn-danger">Delete</button>
                </form>
            </td>
            <?php endif; ?>
        </tr>
        <?php endforeach; ?>
        <?php if (!$products): ?><tr><td colspan="7" class="muted">No products found.</td></tr><?php endif; ?>
        <tr class="no-match" hidden><td colspan="7">No product matches your search.</td></tr>
    </table></div>
    <p class="found"><b id="prodCount"><?= count($products) ?></b> product(s) shown.</p>
</div>
<script src="assets/search.js"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
