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
$sql = "SELECT p.*, c.category_name FROM products p LEFT JOIN categories c ON c.category_id = p.category_id WHERE 1=1";
$params = [];
if ($q !== '') {
    $sql .= " AND (p.product_name LIKE ? OR p.product_code LIKE ? OR p.description LIKE ?)";
    $like = "%$q%"; array_push($params, $like, $like, $like);
}
if ($cat > 0) { $sql .= " AND p.category_id = ?"; $params[] = $cat; }
$sql .= " ORDER BY p.product_name";
$stmt = $pdo->prepare($sql); $stmt->execute($params);
$products = $stmt->fetchAll();
$cats = $pdo->query("SELECT * FROM categories ORDER BY category_name")->fetchAll();

function stock_tag(array $p): string {
    if ($p['quantity'] <= 0) return '<span class="tag tag-out">Out of stock</span>';
    if ($p['quantity'] <= $p['minimum_stock']) return '<span class="tag tag-low">Low</span>';
    return '<span class="tag tag-ok">In stock</span>';
}
?>
<h1>Products</h1>
<div class="card">
    <form method="get" class="toolbar">
        <div class="grow"><label>Search by name, code, or specification</label>
            <input name="q" value="<?= e($q) ?>" placeholder="e.g. 10k resistor, 2N2222, 470uF"></div>
        <div><label>Category</label><select name="category">
            <option value="0">All</option>
            <?php foreach ($cats as $c): ?>
            <option value="<?= (int)$c['category_id'] ?>" <?= $cat === (int)$c['category_id'] ? 'selected' : '' ?>><?= e($c['category_name']) ?></option>
            <?php endforeach; ?></select></div>
        <button class="btn">Search</button>
        <a class="btn btn-gray" href="products.php">Reset</a>
        <?php if (is_admin()): ?><a class="btn" href="product_form.php">+ Add Product</a><?php endif; ?>
    </form>
    <div class="table-wrap"><table>
        <tr><th>Code</th><th>Product</th><th>Category</th><th class="num">Price</th><th class="num">Stock</th><th>Status</th><?php if (is_admin()): ?><th>Actions</th><?php endif; ?></tr>
        <?php foreach ($products as $p): ?>
        <tr>
            <td><?= e($p['product_code']) ?></td>
            <td><strong><?= e($p['product_name']) ?></strong>
                <?php if ($p['description']): ?><div class="muted"><?= e(mb_strimwidth($p['description'], 0, 70, '…')) ?></div><?php endif; ?></td>
            <td><?= e($p['category_name'] ?? '—') ?></td>
            <td class="num"><?= money($p['unit_price']) ?></td>
            <td class="num"><?= (int)$p['quantity'] ?></td>
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
    </table></div>
    <p class="muted"><?= count($products) ?> product(s) shown.</p>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
