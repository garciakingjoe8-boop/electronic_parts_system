<?php
// Full database backup as a .sql file (admin only). Restore by importing it in phpMyAdmin.
require_once __DIR__ . '/includes/functions.php';
require_admin();

while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: application/sql; charset=utf-8');
header('Content-Disposition: attachment; filename="electronic_parts_backup_' . date('Ymd_His') . '.sql"');

echo "-- 2K2R Electronic Parts Shop backup, " . date('Y-m-d H:i:s') . "\n";
echo "SET FOREIGN_KEY_CHECKS=0;\nSET NAMES utf8mb4;\n\n";
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $t) {
    $create = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM)[1];
    echo "DROP TABLE IF EXISTS `$t`;\n$create;\n\n";
    $st = $pdo->query("SELECT * FROM `$t`");
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $vals = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), array_values($row));
        echo "INSERT INTO `$t` VALUES (" . implode(',', $vals) . ");\n";
    }
    echo "\n";
}
echo "SET FOREIGN_KEY_CHECKS=1;\n";
exit;
