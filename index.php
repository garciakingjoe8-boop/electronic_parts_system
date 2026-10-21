<?php
$page_title = 'Dashboard';
require_once __DIR__ . '/includes/header.php';

/* ---------- inventory & returns ---------- */
$totalProducts = (int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
$lowCount = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE quantity > 0 AND quantity <= minimum_stock")->fetchColumn();
$outCount = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE quantity = 0")->fetchColumn();
$stockValue = (float)$pdo->query("SELECT COALESCE(SUM(quantity*unit_price),0) FROM products")->fetchColumn();
$healthy = max(0, $totalProducts - $lowCount - $outCount);
$healthPct = $totalProducts ? round($healthy / $totalProducts * 100) : 0;
$retPending = (int)$pdo->query("SELECT COUNT(*) FROM returns WHERE status='Pending'")->fetchColumn();
$retTotal = (int)$pdo->query("SELECT COUNT(*) FROM returns")->fetchColumn();
$retDone = $retTotal - $retPending;
$retPct = $retTotal ? round($retDone / $retTotal * 100) : 100;
$unitsMonth = (int)$pdo->query("SELECT COALESCE(SUM(si.quantity),0) FROM sale_items si JOIN sales s ON s.sale_id=si.sale_id
    WHERE s.status='completed' AND YEAR(s.sale_date)=YEAR(CURDATE()) AND MONTH(s.sale_date)=MONTH(CURDATE())")->fetchColumn();

/* ---------- sales analytics (completed sales only) ---------- */
$rows = $pdo->query("SELECT DATE(sale_date) d, SUM(total_amount) t, COUNT(*) c FROM sales
    WHERE status='completed' AND sale_date >= MAKEDATE(YEAR(CURDATE())-4, 1) GROUP BY DATE(sale_date)")->fetchAll();
$byDay = [];
foreach ($rows as $r) $byDay[$r['d']] = [(float)$r['t'], (int)$r['c']];

function roll(array $byDay, callable $keyFn): array {
    $o = [];
    foreach ($byDay as $d => $v) {
        $k = $keyFn(new DateTime($d));
        if (!isset($o[$k])) $o[$k] = [0.0, 0];
        $o[$k][0] += $v[0];
        $o[$k][1] += $v[1];
    }
    return $o;
}
function point(string $label, ?array $v): array { return ['l' => $label, 'v' => round($v[0] ?? 0, 2), 'c' => $v[1] ?? 0]; }

$today = new DateTime('today');
$daily = [];
for ($i = 13; $i >= 0; $i--) {
    $d = (clone $today)->modify("-$i days");
    $daily[] = point($d->format('M j'), $byDay[$d->format('Y-m-d')] ?? null);
}
$wk = roll($byDay, fn($d) => $d->format('o-W'));
$monday = (clone $today)->modify('monday this week');
$weekly = [];
for ($i = 11; $i >= 0; $i--) {
    $s = (clone $monday)->modify("-$i weeks");
    $weekly[] = point($s->format('M j'), $wk[$s->format('o-W')] ?? null);
}
$mo = roll($byDay, fn($d) => $d->format('Y-m'));
$firstDay = new DateTime('first day of this month 00:00');
$monthly = [];
for ($i = 11; $i >= 0; $i--) {
    $s = (clone $firstDay)->modify("-$i months");
    $monthly[] = point($s->format("M 'y"), $mo[$s->format('Y-m')] ?? null);
}
$qKey = fn($d) => $d->format('Y') . '-Q' . (int)ceil($d->format('n') / 3);
$qa = roll($byDay, $qKey);
$qStart = new DateTime(sprintf('%s-%02d-01', $today->format('Y'), ((int)ceil($today->format('n') / 3) - 1) * 3 + 1));
$quarterly = [];
for ($i = 7; $i >= 0; $i--) {
    $s = (clone $qStart)->modify('-' . (3 * $i) . ' months');
    $quarterly[] = point('Q' . (int)ceil($s->format('n') / 3) . " '" . $s->format('y'), $qa[$qKey($s)] ?? null);
}
$ya = roll($byDay, fn($d) => $d->format('Y'));
$annual = [];
for ($i = 4; $i >= 0; $i--) {
    $y = (int)$today->format('Y') - $i;
    $annual[] = point((string)$y, $ya[$y] ?? null);
}

$defs = [
    'daily'     => ['Daily',     $daily,     'Today',        'yesterday',    'Last 14 days'],
    'weekly'    => ['Weekly',    $weekly,    'This week',    'last week',    'Last 12 weeks'],
    'monthly'   => ['Monthly',   $monthly,   'This month',   'last month',   'Last 12 months'],
    'quarterly' => ['Quarterly', $quarterly, 'This quarter', 'last quarter', 'Last 8 quarters'],
    'annual'    => ['Annual',    $annual,    'This year',    'last year',    'Last 5 years'],
];
$periods = [];
foreach ($defs as $key => [$label, $series, $curName, $prevName, $rangeName]) {
    $n = count($series);
    $periods[$key] = [
        'label' => $label, 'series' => $series, 'curName' => $curName, 'prevName' => $prevName, 'range' => $rangeName,
        'cur' => $series[$n - 1]['v'], 'prev' => $series[$n - 2]['v'], 'curC' => $series[$n - 1]['c'],
    ];
}
function delta_html(float $cur, float $prev, string $vs): string {
    if ($prev > 0) {
        $p = ($cur - $prev) / $prev * 100;
        $cls = $p > 0 ? 'up' : ($p < 0 ? 'down' : 'flat');
        $txt = $p == 0 ? '— no change' : ($p > 0 ? '▲ ' : '▼ ') . number_format(abs($p), 1) . '%';
    } elseif ($cur > 0) { $cls = 'up'; $txt = '▲ New'; }
    else { $cls = 'flat'; $txt = '— no sales'; }
    return '<span class="delta ' . $cls . '">' . $txt . ' <small>vs ' . e($vs) . '</small></span>';
}

$tNow = $periods['daily']['cur']; $tPrev = $periods['daily']['prev'];
$todayBar = $tPrev > 0 ? min(100, round($tNow / $tPrev * 100)) : ($tNow > 0 ? 100 : 0);
$monthTotal = $periods['monthly']['cur']; $monthCount = $periods['monthly']['curC'];
$avgSale = $monthCount ? $monthTotal / $monthCount : 0;

$low = $pdo->query("SELECT product_code, product_name, quantity, minimum_stock FROM products
                    WHERE quantity <= minimum_stock ORDER BY quantity ASC LIMIT 6")->fetchAll();
$recent = $pdo->query("SELECT s.sale_id, s.sale_date, s.total_amount, s.status, s.customer_name, u.full_name
                       FROM sales s JOIN users u ON u.user_id = s.user_id
                       ORDER BY s.sale_date DESC, s.sale_id DESC LIMIT 6")->fetchAll();
$i = 0;
?>
<h1 class="reveal">Dashboard</h1>
<p class="page-sub reveal" style="--i:1">Your central hub for sales, stock, and shop activity.</p>

<!-- highlight cards -->
<div class="hl-grid">
    <div class="hl reveal" style="--i:<?= ++$i ?>">
        <div class="hl-head">
            <div class="hl-ico"><svg viewBox="0 0 24 24"><circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M2 3h3l2.7 12.4a1 1 0 0 0 1 .8h8.6a1 1 0 0 0 1-.8L20 7H6"/></svg></div>
            <div><div class="hl-title">Sales Today</div><div class="hl-sub">Compared with yesterday</div></div>
            <?php $pt = $tPrev > 0 ? ($tNow - $tPrev) / $tPrev * 100 : null; ?>
            <span class="pill <?= $pt === null ? ($tNow > 0 ? 'up' : '') : ($pt >= 0 ? 'up' : 'down') ?>"><?= $pt === null ? ($tNow > 0 ? 'New' : 'No sales') : (($pt >= 0 ? '▲ ' : '▼ ') . number_format(abs($pt), 1) . '%') ?></span>
        </div>
        <div class="hl-row"><span>Today (<?= (int)$periods['daily']['curC'] ?> sales)</span><b><?= money($tNow) ?></b></div>
        <div class="bar"><i style="--w:<?= $todayBar ?>%"></i></div>
        <div class="hl-row"><span>Yesterday</span><b><?= money($tPrev) ?></b></div>
        <div class="hl-foot"><span>This month: <?= money($monthTotal) ?></span><a class="btn btn-sm" href="sales.php">Show ›</a></div>
    </div>

    <div class="hl reveal" style="--i:<?= ++$i ?>">
        <div class="hl-head">
            <div class="hl-ico"><svg viewBox="0 0 24 24"><path d="M21 8 12 3 3 8v8l9 5 9-5z"/><path d="M3 8l9 5 9-5M12 13v8"/></svg></div>
            <div><div class="hl-title">Stock Health</div><div class="hl-sub"><?= $totalProducts ?> products tracked</div></div>
            <span class="pill <?= ($lowCount + $outCount) ? 'warn' : 'up' ?>"><?= ($lowCount + $outCount) ? 'Needs restock' : 'Healthy' ?></span>
        </div>
        <div class="hl-row"><span>Well stocked (<?= $healthPct ?>%)</span><b><?= $healthy ?> of <?= $totalProducts ?></b></div>
        <div class="bar"><i style="--w:<?= $healthPct ?>%"></i></div>
        <div class="hl-row"><span>Low / out of stock</span><b><?= $lowCount ?> low · <?= $outCount ?> out</b></div>
        <div class="hl-foot"><span>Inventory value: <?= money($stockValue) ?></span><a class="btn btn-sm" href="inventory.php?filter=low">Restock ›</a></div>
    </div>

    <div class="hl reveal" style="--i:<?= ++$i ?>">
        <div class="hl-head">
            <div class="hl-ico"><svg viewBox="0 0 24 24"><path d="M9 14 4 9l5-5"/><path d="M4 9h10a6 6 0 0 1 0 12h-3"/></svg></div>
            <div><div class="hl-title">Returns / Warranty</div><div class="hl-sub">Customer concerns</div></div>
            <span class="pill <?= $retPending ? 'warn' : 'up' ?>"><?= $retPending ? $retPending . ' pending' : 'All clear' ?></span>
        </div>
        <div class="hl-row"><span>Resolved (<?= $retPct ?>%)</span><b><?= $retDone ?> of <?= $retTotal ?></b></div>
        <div class="bar"><i style="--w:<?= $retPct ?>%"></i></div>
        <div class="hl-row"><span>Waiting for action</span><b><?= $retPending ?></b></div>
        <div class="hl-foot"><span>Returns &amp; warranty log</span><a class="btn btn-sm" href="returns.php">Open ›</a></div>
    </div>
</div>

<!-- period totals (click to change the chart) -->
<div class="sum-grid">
<?php foreach ($periods as $key => $P): ?>
    <button type="button" class="sum reveal" style="--i:<?= ++$i ?>" data-period="<?= $key ?>">
        <div class="s-label"><?= e($P['label']) ?> sales</div>
        <div class="s-val" data-count="<?= $P['cur'] ?>" data-money="1"><?= money($P['cur']) ?></div>
        <?= delta_html($P['cur'], $P['prev'], $P['prevName']) ?>
    </button>
<?php endforeach; ?>
</div>

<!-- chart -->
<div class="card chart-card reveal" style="--i:<?= ++$i ?>">
    <div class="chart-top">
        <div>
            <div class="muted">Sales Analytics</div>
            <div class="chart-big" id="bigNum"><?= money($periods['daily']['cur']) ?></div>
            <div><span id="bigDelta" class="delta flat"></span></div>
            <div class="muted" style="font-size:.8rem;margin-top:2px"><span id="chartSub"></span> · <span id="rangeTotal"></span></div>
        </div>
        <div class="tabs">
            <?php foreach ($periods as $key => $P): ?><button type="button" class="tab" data-period="<?= $key ?>"><?= e($P['label']) ?></button><?php endforeach; ?>
        </div>
    </div>
    <div class="chart-box" id="chart"><div class="tip" id="chartTip"></div></div>
</div>

<!-- quick numbers -->
<div class="grid">
    <div class="stat reveal" style="--i:<?= ++$i ?>"><div class="label">Total products</div><div class="value" data-count="<?= $totalProducts ?>"><?= $totalProducts ?></div></div>
    <div class="stat reveal" style="--i:<?= ++$i ?>"><div class="label">Inventory value</div><div class="value" data-count="<?= $stockValue ?>" data-money="1"><?= money($stockValue) ?></div></div>
    <div class="stat reveal" style="--i:<?= ++$i ?>"><div class="label">Units sold this month</div><div class="value" data-count="<?= $unitsMonth ?>"><?= $unitsMonth ?></div></div>
    <div class="stat reveal" style="--i:<?= ++$i ?>"><div class="label">Average sale (this month)</div><div class="value" data-count="<?= $avgSale ?>" data-money="1"><?= money($avgSale) ?></div></div>
</div>

<!-- recent activity -->
<div class="two-col">
    <div class="card reveal" style="--i:<?= ++$i ?>">
        <div class="card-head"><h2>Recent Sales</h2><a href="sales.php">View all →</a></div>
        <?php if (!$recent): ?><p class="empty">No sales recorded yet. <a href="sales_new.php">Record a sale</a>.</p>
        <?php else: ?>
        <div class="table-wrap"><table>
            <tr><th>Sale #</th><th>Date</th><th>Cashier</th><th class="num">Total</th><th></th></tr>
            <?php foreach ($recent as $s): ?>
            <tr><td>#<?= (int)$s['sale_id'] ?></td><td><?= e($s['sale_date']) ?></td><td><?= e($s['full_name']) ?></td>
                <td class="num"><?= money($s['total_amount']) ?></td>
                <td><?php if ($s['status'] === 'voided'): ?><span class="tag tag-out">Voided</span> <?php endif; ?><a href="sale_view.php?id=<?= (int)$s['sale_id'] ?>">View</a></td></tr>
            <?php endforeach; ?>
        </table></div>
        <?php endif; ?>
    </div>
    <div class="card reveal" style="--i:<?= ++$i ?>">
        <div class="card-head"><h2>Low-stock Alerts</h2><a href="inventory.php?filter=low">View all →</a></div>
        <?php if (!$low): ?><p class="empty">All products are above their minimum stock. 🎉</p>
        <?php else: ?>
        <div class="table-wrap"><table>
            <tr><th>Code</th><th>Product</th><th class="num">On hand</th><th class="num">Min</th></tr>
            <?php foreach ($low as $p): ?>
            <tr><td><?= e($p['product_code']) ?></td><td><?= e($p['product_name']) ?></td>
                <td class="num"><span class="tag <?= $p['quantity'] == 0 ? 'tag-out' : 'tag-low' ?>"><?= (int)$p['quantity'] ?></span></td>
                <td class="num"><?= (int)$p['minimum_stock'] ?></td></tr>
            <?php endforeach; ?>
        </table></div>
        <?php endif; ?>
    </div>
</div>

<script>window.DASH = { periods: <?= json_encode($periods, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?> };</script>
<script src="assets/dashboard.js"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
