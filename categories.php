<?php
$page_title = 'Categories';
require_once __DIR__ . '/includes/header.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $name = trim($_POST['category_name'] ?? '');
    try {
        if ($action === 'add' && $name !== '') {
            $pdo->prepare("INSERT INTO categories (category_name) VALUES (?)")->execute([$name]);
            flash('success', 'Category added.');
        } elseif ($action === 'edit' && $name !== '') {
            $pdo->prepare("UPDATE categories SET category_name=? WHERE category_id=?")->execute([$name, (int)$_POST['category_id']]);
            flash('success', 'Category updated.');
        } elseif ($action === 'delete') {
            $pdo->prepare("DELETE FROM categories WHERE category_id=?")->execute([(int)$_POST['category_id']]);
            flash('success', 'Category deleted. Its products are now uncategorized.');
        }
    } catch (PDOException $ex) {
        flash('error', $ex->getCode() == 23000 ? 'That category name already exists.' : 'Database error.');
    }
    redirect('categories.php');
}

$editId = (int)($_GET['edit'] ?? 0);
$cats = $pdo->query("SELECT c.*, COUNT(p.product_id) AS n FROM categories c
                     LEFT JOIN products p ON p.category_id = c.category_id
                     GROUP BY c.category_id ORDER BY c.category_name")->fetchAll();
?>
<h1>Categories</h1>
<div class="card">
    <form method="post" class="toolbar">
        <?= csrf_field() ?><input type="hidden" name="action" value="add">
        <div class="grow"><label>New category</label><input name="category_name" required maxlength="100" placeholder="e.g. Transistors"></div>
        <button class="btn">Add</button>
    </form>
</div>
<div class="card table-wrap">
<table>
    <tr><th>Category</th><th class="num">Products</th><th style="width:240px">Actions</th></tr>
    <?php foreach ($cats as $c): ?>
    <tr>
        <?php if ($editId === (int)$c['category_id']): ?>
        <td colspan="2"><form method="post" class="toolbar" style="margin:0">
            <?= csrf_field() ?><input type="hidden" name="action" value="edit"><input type="hidden" name="category_id" value="<?= (int)$c['category_id'] ?>">
            <input class="grow" name="category_name" value="<?= e($c['category_name']) ?>" required>
            <button class="btn btn-sm">Save</button> <a class="btn btn-sm btn-gray" href="categories.php">Cancel</a>
        </form></td><td></td>
        <?php else: ?>
        <td><?= e($c['category_name']) ?></td><td class="num"><?= (int)$c['n'] ?></td>
        <td>
            <a class="btn btn-sm" href="categories.php?edit=<?= (int)$c['category_id'] ?>">Edit</a>
            <form method="post" class="inline" onsubmit="return confirm('Delete this category?')">
                <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="category_id" value="<?= (int)$c['category_id'] ?>">
                <button class="btn btn-sm btn-danger">Delete</button>
            </form>
        </td>
        <?php endif; ?>
    </tr>
    <?php endforeach; ?>
    <?php if (!$cats): ?><tr><td colspan="3" class="muted">No categories yet.</td></tr><?php endif; ?>
</table>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
