<?php
$page_title = 'Product Form';
require_once __DIR__ . '/includes/header.php';
require_admin();

$id = (int)($_GET['id'] ?? $_POST['product_id'] ?? 0);
$p = ['product_code'=>'','product_name'=>'','category_id'=>'','description'=>'','unit_price'=>'','quantity'=>0,'minimum_stock'=>5];
if ($id) {
    $stmt = $pdo->prepare("SELECT * FROM products WHERE product_id=?");
    $stmt->execute([$id]);
    $p = $stmt->fetch();
    if (!$p) { flash('error', 'Product not found.'); redirect('products.php'); }
}
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = trim($_POST['product_code'] ?? '');
    $name = trim(preg_replace('/\s+/u', ' ', $_POST['product_name'] ?? ''));   // tidy extra spaces
    $catId = (int)($_POST['category_id'] ?? 0) ?: null;
    $desc = trim($_POST['description'] ?? '');
    $price = $_POST['unit_price'] ?? '';
    $minStock = $_POST['minimum_stock'] ?? '';
    $qty = (int)($_POST['quantity'] ?? 0);

    if ($code === '') $errors[] = 'Product code is required.';
    if ($name === '') $errors[] = 'Product name is required.';
    if (!is_numeric($price) || $price < 0) $errors[] = 'Enter a valid price.';
    if (!ctype_digit((string)$minStock)) $errors[] = 'Minimum stock must be a whole number (0 or more).';
    if (!ctype_digit(trim((string)($_POST['quantity'] ?? '0')))) $errors[] = 'Quantity must be a whole number (0 or more).';
    $stockNote = trim($_POST['stock_note'] ?? '');

    // Duplicate check: same code OR same name (ignoring upper/lower case and extra spaces)
    $name = preg_replace('/\s+/', ' ', $name);   // collapse repeated spaces so "10k  Resistor" equals "10k Resistor"
    if (!$errors) {
        $dup = $pdo->prepare("SELECT product_code, product_name FROM products
                              WHERE (product_code = ? OR LOWER(TRIM(REPLACE(REPLACE(product_name, '  ', ' '), '  ', ' '))) = LOWER(?)) AND product_id <> ? LIMIT 1");
        $dup->execute([$code, $name, $id]);
        if ($d = $dup->fetch()) {
            $errors[] = strcasecmp($d['product_code'], $code) === 0
                ? "Product code \"{$d['product_code']}\" is already used by \"{$d['product_name']}\". Use a different code."
                : "\"{$d['product_name']}\" already exists (code {$d['product_code']}). Edit that product or use Inventory > Stock-in to add stock instead.";
        }
    }

    if (!$errors) {
        try {
            if ($id) {
                $pdo->beginTransaction();
                $cur = $pdo->prepare("SELECT quantity FROM products WHERE product_id=? FOR UPDATE");
                $cur->execute([$id]);
                $oldQty = (int)$cur->fetchColumn();
                $pdo->prepare("UPDATE products SET product_code=?, product_name=?, category_id=?, description=?, unit_price=?, quantity=?, minimum_stock=? WHERE product_id=?")
                    ->execute([$code, $name, $catId, $desc, $price, $qty, $minStock, $id]);
                $delta = $qty - $oldQty;
                if ($delta !== 0) {
                    $pdo->prepare("INSERT INTO stock_movements (product_id, user_id, movement_type, quantity, notes) VALUES (?,?, 'ADJUSTMENT', ?, ?)")
                        ->execute([$id, $_SESSION['user_id'], $delta, mb_substr($stockNote !== '' ? $stockNote : 'Quantity edited in product form', 0, 255)]);
                }
                $pdo->commit();
                flash('success', 'Product updated.' . ($delta !== 0 ? " Stock changed from $oldQty to $qty." : ''));
            } else {
                $pdo->beginTransaction();
                $pdo->prepare("INSERT INTO products (product_code, product_name, category_id, description, unit_price, quantity, minimum_stock) VALUES (?,?,?,?,?,?,?)")
                    ->execute([$code, $name, $catId, $desc, $price, $qty, $minStock]);
                $newId = (int)$pdo->lastInsertId();
                if ($qty > 0) {
                    $pdo->prepare("INSERT INTO stock_movements (product_id, user_id, movement_type, quantity, notes) VALUES (?,?, 'IN', ?, 'Opening stock')")
                        ->execute([$newId, $_SESSION['user_id'], $qty]);
                }
                $pdo->commit();
                flash('success', 'Product added.');
            }
            redirect('products.php');
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = $ex->getCode() == 23000 ? 'That product code already exists.' : 'Database error. Please try again.';
        }
    }
    $p = array_merge($p, ['product_code'=>$code,'product_name'=>$name,'category_id'=>$catId,'description'=>$desc,'unit_price'=>$price,'quantity'=>$qty,'minimum_stock'=>$minStock]);
}
$cats = $pdo->query("SELECT * FROM categories ORDER BY category_name")->fetchAll();
?>
<h1><?= $id ? 'Edit Product' : 'Add Product' ?></h1>
<?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>
<div class="card">
<form method="post">
    <?= csrf_field() ?><input type="hidden" name="product_id" value="<?= $id ?>">
    <div class="form-grid">
        <div><label>Product code / SKU *</label><input name="product_code" value="<?= e($p['product_code']) ?>" required maxlength="50"></div>
        <div><label>Product name *</label><input name="product_name" value="<?= e($p['product_name']) ?>" required maxlength="150"></div>
        <div><label>Category</label><select name="category_id"><option value="0">— None —</option>
            <?php foreach ($cats as $c): ?><option value="<?= (int)$c['category_id'] ?>" <?= (int)$p['category_id'] === (int)$c['category_id'] ? 'selected' : '' ?>><?= e($c['category_name']) ?></option><?php endforeach; ?>
        </select></div>
        <div><label>Unit price (₱) *</label><input type="number" step="0.01" min="0" name="unit_price" value="<?= e($p['unit_price']) ?>" required></div>
        <div><label><?= $id ? 'Current quantity (stock on hand)' : 'Opening quantity' ?></label>
            <input type="number" min="0" name="quantity" value="<?= (int)$p['quantity'] ?>" required></div>
        <?php if ($id): ?>
        <div><label>Reason for stock change (optional)</label>
            <input name="stock_note" maxlength="255" placeholder="e.g. Recount, damaged items"></div>
        <?php endif; ?>
        <div><label>Minimum stock (low-stock level)</label><input type="number" min="0" name="minimum_stock" value="<?= e($p['minimum_stock']) ?>" required></div>
    </div>
    <label>Description / specifications</label>
    <textarea name="description" rows="3" placeholder="e.g. 10kΩ, 1/4W, ±5% carbon film"><?= e($p['description']) ?></textarea>
    <p><button class="btn">Save Product</button> <a class="btn btn-gray" href="products.php">Cancel</a></p>
</form>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
