<?php
$page_title = 'New Sale';
require_once __DIR__ . '/includes/header.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ids = $_POST['product_id'] ?? [];
    $qtys = $_POST['quantity'] ?? [];
    $customer = trim($_POST['customer_name'] ?? '');
    $paidRaw = trim($_POST['amount_paid'] ?? '');
    $paid = $paidRaw === '' ? null : (is_numeric($paidRaw) && $paidRaw >= 0 ? round((float)$paidRaw, 2) : -1);

    // Merge duplicate products and validate input
    $cart = [];
    foreach ($ids as $i => $pid) {
        $pid = (int)$pid; $qty = (int)($qtys[$i] ?? 0);
        if ($pid > 0 && $qty > 0) $cart[$pid] = ($cart[$pid] ?? 0) + $qty;
    }
    ksort($cart); // consistent lock order avoids deadlocks

    try {
        if (!$cart) throw new RuntimeException('Add at least one product with a quantity of 1 or more.');
        if ($paid === -1) throw new RuntimeException('Amount received must be a valid number.');
        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO sales (user_id, customer_name, total_amount) VALUES (?, ?, 0)")
            ->execute([$_SESSION['user_id'], $customer !== '' ? mb_substr($customer, 0, 100) : null]);
        $saleId = (int)$pdo->lastInsertId();

        $sel = $pdo->prepare("SELECT product_name, unit_price, quantity FROM products WHERE product_id=? FOR UPDATE");
        $insItem = $pdo->prepare("INSERT INTO sale_items (sale_id,product_id,quantity,unit_price,subtotal) VALUES (?,?,?,?,?)");
        $upd = $pdo->prepare("UPDATE products SET quantity = quantity - ? WHERE product_id=?");
        $mov = $pdo->prepare("INSERT INTO stock_movements (product_id,user_id,movement_type,quantity,notes) VALUES (?,?, 'OUT', ?, ?)");

        $total = 0;
        foreach ($cart as $pid => $qty) {
            $sel->execute([$pid]);
            $p = $sel->fetch();
            if (!$p) throw new RuntimeException('A selected product no longer exists.');
            if ($p['quantity'] < $qty) {
                throw new RuntimeException("Not enough stock for {$p['product_name']} (available: {$p['quantity']}, requested: $qty).");
            }
            $sub = round($p['unit_price'] * $qty, 2);   // price always taken from the database
            $total += $sub;
            $insItem->execute([$saleId, $pid, $qty, $p['unit_price'], $sub]);
            $upd->execute([$qty, $pid]);
            $mov->execute([$pid, $_SESSION['user_id'], $qty, "Sale #$saleId"]);
        }
        if ($paid !== null && $paid + 0.001 < $total) {
            throw new RuntimeException('Amount received (' . money($paid) . ') is less than the total (' . money($total) . '). Nothing was saved.');
        }
        $pdo->prepare("UPDATE sales SET total_amount=?, amount_paid=? WHERE sale_id=?")->execute([$total, $paid, $saleId]);
        $pdo->commit();
        flash('success', "Sale #$saleId recorded. Total: " . money($total));
        redirect("sale_view.php?id=$saleId");
    } catch (RuntimeException $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', $ex->getMessage());
        redirect('sales_new.php');
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log($ex->getMessage());
        flash('error', 'Database error. The sale was not saved and stock was not changed.');
        redirect('sales_new.php');
    }
}

$products = $pdo->query("SELECT product_id, product_code, product_name, unit_price, quantity FROM products WHERE quantity > 0 ORDER BY product_name")->fetchAll();
$jsProducts = [];
foreach ($products as $p) {
    $jsProducts[] = ['id' => (int)$p['product_id'], 'label' => $p['product_code'].' — '.$p['product_name'],
                     'price' => (float)$p['unit_price'], 'stock' => (int)$p['quantity']];
}
?>
<h1>Record a Sale</h1>
<?php if (!$products): ?>
    <div class="alert alert-info">No products are in stock. Add products and stock first.</div>
<?php else: ?>
<div class="card">
    <label>Quick find (filters the dropdowns)</label>
    <input id="filter" placeholder="Type a product name or code…" autocomplete="off">
</div>
<form method="post" id="saleForm" class="card">
    <?= csrf_field() ?>
    <div class="table-wrap"><table id="lines">
        <thead><tr><th>Product</th><th class="num" style="width:110px">Price</th><th style="width:110px">Qty</th><th class="num" style="width:130px">Subtotal</th><th style="width:60px"></th></tr></thead>
        <tbody></tbody>
    </table></div>
    <p><button type="button" class="btn btn-gray btn-sm" id="addLine">+ Add item</button></p>
    <div class="total-box">Total: <span id="total">₱0.00</span></div>
    <div class="form-grid">
        <div><label>Customer name (optional)</label><input name="customer_name" maxlength="100"></div>
        <div><label>Amount received (₱)</label><input type="number" step="0.01" min="0" name="amount_paid" id="paid"></div>
        <div><label>Change</label><input id="change" value="₱0.00" disabled></div>
    </div>
    <p class="right"><button class="btn">Complete Sale</button></p>
</form>
<script>
const products = <?= json_encode($jsProducts, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const tbody = document.querySelector('#lines tbody');
const fmt = n => '₱' + n.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});

function optionsHtml(term, keep) {
    term = (term || '').toLowerCase();
    return '<option value="">— Select product —</option>' + products
        .filter(p => !term || p.id == keep || p.label.toLowerCase().includes(term))
        .map(p => `<option value="${p.id}">${p.label.replace(/</g,'&lt;')} (stock ${p.stock})</option>`).join('');
}
function addLine() {
    const tr = document.createElement('tr');
    tr.innerHTML = `<td><select name="product_id[]" required>${optionsHtml(document.getElementById('filter').value)}</select></td>
        <td class="num price">₱0.00</td>
        <td><input type="number" name="quantity[]" min="1" value="1" required></td>
        <td class="num sub">₱0.00</td>
        <td><button type="button" class="btn btn-sm btn-danger rm">✕</button></td>`;
    tbody.appendChild(tr);
    recalc();
}
function recalc() {
    let total = 0;
    tbody.querySelectorAll('tr').forEach(tr => {
        const p = products.find(x => x.id == tr.querySelector('select').value);
        const qtyInput = tr.querySelector('input');
        const qty = parseInt(qtyInput.value) || 0;
        if (p) { qtyInput.max = p.stock; }
        const sub = p ? p.price * qty : 0;
        tr.querySelector('.price').textContent = p ? fmt(p.price) : '₱0.00';
        tr.querySelector('.sub').textContent = fmt(sub);
        total += sub;
    });
    document.getElementById('total').textContent = fmt(total);
    window.saleTotal = total;
    updateChange();
}
function updateChange() {
    const paid = parseFloat(document.getElementById('paid').value);
    const c = document.getElementById('change');
    c.value = isNaN(paid) ? '₱0.00' : (paid + 0.001 < window.saleTotal ? 'Short by ' + fmt(window.saleTotal - paid) : fmt(paid - window.saleTotal));
}
document.getElementById('paid').addEventListener('input', updateChange);
tbody.addEventListener('input', recalc);
tbody.addEventListener('change', recalc);
tbody.addEventListener('click', e => {
    if (e.target.classList.contains('rm')) { e.target.closest('tr').remove(); if (!tbody.children.length) addLine(); recalc(); }
});
document.getElementById('addLine').onclick = addLine;
document.getElementById('filter').addEventListener('input', e => {
    tbody.querySelectorAll('select').forEach(sel => {
        const cur = sel.value;
        sel.innerHTML = optionsHtml(e.target.value, cur);
        if (cur) sel.value = cur;
    });
});
document.getElementById('saleForm').addEventListener('submit', e => {
    if (!confirm('Complete this sale? Stock will be deducted.')) e.preventDefault();
});
addLine();
</script>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
