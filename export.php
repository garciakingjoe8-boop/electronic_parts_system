<?php
// CSV export (products or sales). Does not use header.php because it outputs a file.
require_once __DIR__ . '/includes/functions.php';
require_login();

$type = $_GET['type'] ?? 'products';
function safe($v) { $v = (string)$v; return ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) ? "'" . $v : $v; } // block spreadsheet formula injection

while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $type . '_' . date('Ymd_His') . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows ₱ and accents correctly

if ($type === 'sales') {
    $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : '1970-01-01';
    $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : '2999-12-31';
    fputcsv($out, ['Sale #','Date','Customer','Cashier','Status','Product code','Product','Qty','Unit price','Subtotal','Sale total']);
    $st = $pdo->prepare("SELECT s.sale_id, s.sale_date, s.customer_name, u.full_name, s.status, p.product_code, p.product_name,
                                si.quantity, si.unit_price, si.subtotal, s.total_amount
                         FROM sales s JOIN users u ON u.user_id=s.user_id JOIN sale_items si ON si.sale_id=s.sale_id
                         JOIN products p ON p.product_id=si.product_id
                         WHERE DATE(s.sale_date) BETWEEN ? AND ? ORDER BY s.sale_id, si.sale_item_id");
    $st->execute([$from, $to]);
} else {
    fputcsv($out, ['Code','Product','Category','Description','Unit price','Quantity','Minimum stock']);
    $st = $pdo->query("SELECT p.product_code, p.product_name, c.category_name, p.description, p.unit_price, p.quantity, p.minimum_stock
                       FROM products p LEFT JOIN categories c ON c.category_id=p.category_id ORDER BY p.product_name");
}
while ($row = $st->fetch(PDO::FETCH_NUM)) fputcsv($out, array_map('safe', $row));
fclose($out);
exit;
