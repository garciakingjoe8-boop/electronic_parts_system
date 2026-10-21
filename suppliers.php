<?php
$page_title = 'Suppliers';
require_once __DIR__ . '/includes/header.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $name = trim($_POST['supplier_name'] ?? '');
    $contact = trim($_POST['contact_info'] ?? '');
    try {
        if ($action === 'add' && $name !== '') {
            $pdo->prepare("INSERT INTO suppliers (supplier_name, contact_info) VALUES (?,?)")->execute([$name, $contact ?: null]);
            flash('success', 'Supplier added.');
        } elseif ($action === 'edit' && $name !== '') {
            $pdo->prepare("UPDATE suppliers SET supplier_name=?, contact_info=? WHERE supplier_id=?")->execute([$name, $contact ?: null, (int)$_POST['supplier_id']]);
            flash('success', 'Supplier updated.');
        } elseif ($action === 'delete') {
            $sid = (int)$_POST['supplier_id'];
            $pdo->prepare("UPDATE stock_movements SET supplier_id=NULL WHERE supplier_id=?")->execute([$sid]);
            $pdo->prepare("DELETE FROM suppliers WHERE supplier_id=?")->execute([$sid]);
            flash('success', 'Supplier deleted.');
        }
    } catch (PDOException $ex) {
        flash('error', $ex->getCode() == 23000 ? 'That supplier name already exists.' : 'Database error.');
    }
    redirect('suppliers.php');
}
$editId = (int)($_GET['edit'] ?? 0);
$rows = $pdo->query("SELECT sp.*, (SELECT COALESCE(SUM(quantity),0) FROM stock_movements m WHERE m.supplier_id=sp.supplier_id AND m.movement_type='IN') AS received
                     FROM suppliers sp ORDER BY supplier_name")->fetchAll();
?>
<h1>Suppliers</h1>
<div class="card"><form method="post" class="toolbar">
    <?= csrf_field() ?><input type="hidden" name="action" value="add">
    <div class="grow"><label>Supplier name</label><input name="supplier_name" required maxlength="100"></div>
    <div class="grow"><label>Contact (phone / address)</label><input name="contact_info" maxlength="150"></div>
    <button class="btn">Add</button>
</form></div>
<div class="card table-wrap"><table>
    <tr><th>Supplier</th><th>Contact</th><th class="num">Units received</th><th style="width:200px">Actions</th></tr>
    <?php foreach ($rows as $r): ?>
    <tr>
    <?php if ($editId === (int)$r['supplier_id']): ?>
        <td colspan="4"><form method="post" class="toolbar" style="margin:0">
            <?= csrf_field() ?><input type="hidden" name="action" value="edit"><input type="hidden" name="supplier_id" value="<?= (int)$r['supplier_id'] ?>">
            <input class="grow" name="supplier_name" value="<?= e($r['supplier_name']) ?>" required>
            <input class="grow" name="contact_info" value="<?= e($r['contact_info']) ?>">
            <button class="btn btn-sm">Save</button> <a class="btn btn-sm btn-gray" href="suppliers.php">Cancel</a>
        </form></td>
    <?php else: ?>
        <td><?= e($r['supplier_name']) ?></td><td><?= e($r['contact_info'] ?? '—') ?></td><td class="num"><?= (int)$r['received'] ?></td>
        <td><a class="btn btn-sm" href="suppliers.php?edit=<?= (int)$r['supplier_id'] ?>">Edit</a>
            <form method="post" class="inline" onsubmit="return confirm('Delete this supplier?')"><?= csrf_field() ?>
                <input type="hidden" name="action" value="delete"><input type="hidden" name="supplier_id" value="<?= (int)$r['supplier_id'] ?>">
                <button class="btn btn-sm btn-danger">Delete</button></form></td>
    <?php endif; ?>
    </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="4" class="muted">No suppliers yet.</td></tr><?php endif; ?>
</table></div>
<?php require __DIR__ . '/includes/footer.php'; ?>
