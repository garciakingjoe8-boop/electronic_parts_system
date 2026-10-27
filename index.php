<?php
$page_title = 'Dashboard';
require_once __DIR__ . '/includes/header.php';

/* ---------- inventory & returns ---------- */
$totalProducts = (int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
$newThisWeek = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE created_at >= (NOW() - INTERVAL 7 DAY)")->fetchColumn();
$lowCount = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE quantity > 0 AND quantity <= minimum_stock")->fetchColumn();
$outCount = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE quantity = 0")->fetchColumn();
$needRestock = $lowCount + $outCount;
$retPending = (int)$pdo->query("SELECT COUNT(*) FROM returns WHERE status='Pending'")->fetchColumn();

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
    $periods[$key] = ['label' => $label, 'series' => $series, 'curName' => $curName, 'prevName' => $prevName, 'range' => $rangeName,
        'cur' => $series[$n - 1]['v'], 'prev' => $series[$n - 2]['v'], 'curC' => $series[$n - 1]['c']];
}
function pct_change(float $cur, float $prev): ?float { return $prev > 0 ? ($cur - $prev) / $prev * 100 : null; }
function delta_html(float $cur, float $prev, string $vs): string {
    $p = pct_change($cur, $prev);
    if ($p !== null) {
        $cls = $p > 0 ? 'up' : ($p < 0 ? 'down' : 'flat');
        $txt = $p == 0 ? '— no change' : ($p > 0 ? '▲ ' : '▼ ') . number_format(abs($p), 1) . '%';
    } elseif ($cur > 0) { $cls = 'up'; $txt = '▲ New'; }
    else { $cls = 'flat'; $txt = '— none'; }
    return '<span class="delta ' . $cls . '">' . $txt . ' <small>vs ' . e($vs) . '</small></span>';
}
$monthCur = $periods['monthly']['cur']; $monthPrev = $periods['monthly']['prev'];
$dayCur = $periods['daily']['cur'];     $dayPrev = $periods['daily']['prev'];
$pm = pct_change($monthCur, $monthPrev); $pd = pct_change($dayCur, $dayPrev);

/* ---------- categories donut ---------- */
$catRows = $pdo->query("SELECT COALESCE(c.category_name,'Uncategorized') name, COUNT(*) n FROM products p
    LEFT JOIN categories c ON c.category_id=p.category_id GROUP BY name ORDER BY n DESC")->fetchAll();
$cats = array_slice($catRows, 0, 5);
$others = array_sum(array_column(array_slice($catRows, 5), 'n'));
if ($others > 0) $cats[] = ['name' => 'Others', 'n' => $others];
$palette = ['#22d3ee', '#3b82f6', '#a855f7', '#f59e0b', '#ec4899', '#94a3d8'];
$R = 70; $C = 2 * M_PI * $R;

/* ---------- lists ---------- */
$top = $pdo->query("SELECT p.product_name, SUM(si.quantity) q FROM sale_items si
    JOIN sales s ON s.sale_id=si.sale_id JOIN products p ON p.product_id=si.product_id
    WHERE s.status='completed' AND s.sale_date >= (NOW() - INTERVAL 30 DAY)
    GROUP BY p.product_id ORDER BY q DESC LIMIT 5")->fetchAll();
$topMax = $top ? max(array_column($top, 'q')) : 1;
$recent = $pdo->query("SELECT s.sale_id, s.sale_date, s.total_amount, s.status, u.full_name,
    (SELECT COALESCE(SUM(quantity),0) FROM sale_items WHERE sale_id=s.sale_id) AS items
    FROM sales s JOIN users u ON u.user_id=s.user_id ORDER BY s.sale_date DESC, s.sale_id DESC LIMIT 5")->fetchAll();
$low = $pdo->query("SELECT product_name, quantity, minimum_stock FROM products
    WHERE quantity <= minimum_stock ORDER BY quantity ASC, product_name LIMIT 5")->fetchAll();
$i = 0;
?>
<section class="welcome">
    <h1 class="reveal"><span class="wave">👋</span> Welcome Back, <?= e($_SESSION['full_name']) ?>!</h1>
    <p class="reveal" style="--i:1">Here’s what’s happening in your shop today.</p>
</section>

<div class="kpis">
    <div class="kpi k-purple reveal" style="--i:<?= ++$i ?>">
        <div class="kico"><svg viewBox="0 0 24 24"><path d="M21 8 12 3 3 8v8l9 5 9-5z"/><path d="M3 8l9 5 9-5M12 13v8"/></svg></div>
        <div><div class="klabel">Total Products</div><div class="kval" data-count="<?= $totalProducts ?>"><?= $totalProducts ?></div>
            <div class="ksub <?= $newThisWeek ? '' : 'flat' ?>">↑ <?= $newThisWeek ?> new this week</div></div>
    </div>
    <div class="kpi k-green reveal" style="--i:<?= ++$i ?>">
        <div class="kico"><svg viewBox="0 0 24 24"><circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M2 3h3l2.7 12.4a1 1 0 0 0 1 .8h8.6a1 1 0 0 0 1-.8L20 7H6"/></svg></div>
        <div><div class="klabel">Sales This Month</div><div class="kval" data-count="<?= $monthCur ?>" data-money="1"><?= money($monthCur) ?></div>
            <div class="ksub <?= $pm === null ? ($monthCur > 0 ? '' : 'flat') : ($pm >= 0 ? '' : 'down') ?>"><?= $pm === null ? ($monthCur > 0 ? '↑ New' : '— no sales yet') : (($pm >= 0 ? '↑ ' : '↓ ') . number_format(abs($pm), 1) . '% from last month') ?></div></div>
    </div>
    <div class="kpi k-orange reveal" style="--i:<?= ++$i ?>">
        <div class="kico"><svg viewBox="0 0 24 24"><path d="M12 3 2 20h20z"/><path d="M12 10v5M12 18v.01"/></svg></div>
        <div><div class="klabel">Low Stock Items</div><div class="kval" data-count="<?= $needRestock ?>"><?= $needRestock ?></div>
            <div class="ksub <?= $needRestock ? 'warn' : '' ?>"><?= $needRestock ? '⚠ Need restocking' : '✓ All well stocked' ?></div></div>
    </div>
    <div class="kpi k-blue reveal" style="--i:<?= ++$i ?>">
        <div class="kico"><svg viewBox="0 0 24 24"><path d="M3 12h4l3-8 4 16 3-8h4"/></svg></div>
        <div><div class="klabel">Sales Today (<?= (int)$periods['daily']['curC'] ?>)</div><div class="kval" data-count="<?= $dayCur ?>" data-money="1"><?= money($dayCur) ?></div>
            <div class="ksub <?= $pd === null ? ($dayCur > 0 ? '' : 'flat') : ($pd >= 0 ? '' : 'down') ?>"><?= $pd === null ? ($dayCur > 0 ? '↑ New today' : '— no sales yet') : (($pd >= 0 ? '↑ ' : '↓ ') . number_format(abs($pd), 1) . '% from yesterday') ?></div></div>
    </div>
</div>

<div class="dash">
    <!-- Sales overview -->
    <section class="dcard a-sales reveal" style="--i:<?= ++$i ?>">
        <div class="dhead">
            <svg class="hi" viewBox="0 0 24 24"><path d="M5 20V11M11 20V4M17 20v-7M3 20h18"/></svg><h2>Sales Overview</h2>
            <select id="periodSel" aria-label="Period">
                <?php foreach ($periods as $key => $P): ?><option value="<?= $key ?>"><?= e($P['label']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="s-meta"><span class="chart-big" id="bigNum"><?= money($periods['daily']['cur']) ?></span><span id="bigDelta" class="delta flat"></span></div>
        <div class="s-sub"><span id="chartSub"></span> · <span id="rangeTotal"></span></div>
        <div class="chart-box" id="chart"><div class="tip" id="chartTip"></div></div>
        <div class="mini">
            <?php foreach ($periods as $key => $P): ?>
            <button type="button" class="sum" data-period="<?= $key ?>">
                <div class="s-label"><?= e($P['label']) ?></div>
                <div class="s-val" data-count="<?= $P['cur'] ?>" data-money="1" data-dec="0">₱<?= number_format($P['cur'], 0) ?></div>
                <?= delta_html($P['cur'], $P['prev'], $P['prevName']) ?>
            </button>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- Categories -->
    <section class="dcard a-cats reveal" style="--i:<?= ++$i ?>">
        <div class="dhead"><svg class="hi" viewBox="0 0 24 24"><path d="M21 12a9 9 0 1 1-9-9v9z"/><path d="M15 3.5A9 9 0 0 1 20.5 9H15z"/></svg><h2>Product Categories</h2></div>
        <?php if (!$totalProducts): ?><p class="empty">No products yet. <a href="product_form.php">Add your first product</a>.</p>
        <?php else: ?>
        <div class="donut-wrap">
            <div class="donut">
                <svg viewBox="0 0 200 200">
                    <?php $off = 0; foreach ($cats as $k => $c):
                        $len = max(0, $C * $c['n'] / $totalProducts - 3); $gap = $C - $len; ?>
                    <circle cx="100" cy="100" r="<?= $R ?>" stroke="<?= $palette[$k % count($palette)] ?>"
                            style="--c:<?= round($C, 2) ?>;--len:<?= round($len, 2) ?>;--gap:<?= round($gap, 2) ?>;--k:<?= $k ?>"
                            stroke-dasharray="<?= round($len, 2) ?> <?= round($gap, 2) ?>" stroke-dashoffset="<?= round(-$off, 2) ?>"/>
                    <?php $off += $C * $c['n'] / $totalProducts; endforeach; ?>
                </svg>
                <div class="mid"><b data-count="<?= $totalProducts ?>"><?= $totalProducts ?></b><span>Products</span></div>
            </div>
            <div class="legend">
                <?php foreach ($cats as $k => $c): ?>
                <div><i style="background:<?= $palette[$k % count($palette)] ?>;color:<?= $palette[$k % count($palette)] ?>"></i><span><?= e($c['name']) ?></span><b><?= (int)$c['n'] ?></b></div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </section>

    <!-- Recent sales -->
    <section class="dcard a-recent reveal" style="--i:<?= ++$i ?>">
        <div class="dhead"><svg class="hi" viewBox="0 0 24 24"><path d="M6 3h12v18l-3-2-3 2-3-2-3 2z"/><path d="M9 8h6M9 12h6"/></svg><h2>Recent Sales</h2><a class="more" href="sales.php">View All</a></div>
        <?php if (!$recent): ?><p class="empty">No sales recorded yet. <a href="sales_new.php">Record a sale</a>.</p>
        <?php else: ?>
        <div class="table-wrap"><table>
            <tr><th>Date</th><th>Items</th><th class="num">Total</th><th>Staff</th></tr>
            <?php foreach ($recent as $s): ?>
            <tr><td><a href="sale_view.php?id=<?= (int)$s['sale_id'] ?>"><?= e(date('M j, Y', strtotime($s['sale_date']))) ?></a></td>
                <td><?= (int)$s['items'] ?> items</td>
                <td class="num"><?= $s['status'] === 'voided' ? '<span class="tag tag-out">Voided</span>' : money($s['total_amount']) ?></td>
                <td><?= e(strtok($s['full_name'], ' ')) ?></td></tr>
            <?php endforeach; ?>
        </table></div>
        <?php endif; ?>
    </section>

    <!-- Top selling -->
    <section class="dcard a-top reveal" style="--i:<?= ++$i ?>">
        <div class="dhead"><svg class="hi" viewBox="0 0 24 24"><circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M2 3h3l2.7 12.4a1 1 0 0 0 1 .8h8.6a1 1 0 0 0 1-.8L20 7H6"/></svg><h2>Top Selling Products</h2><a class="more" href="reports.php">View All</a></div>
        <?php if (!$top): ?><p class="empty">No sales in the last 30 days yet.</p>
        <?php else: ?>
        <div class="top-list">
            <?php foreach ($top as $k => $t): ?>
            <div class="toprow">
                <span class="n"><?= $k + 1 ?>.</span>
                <span class="pico"><svg viewBox="0 0 24 24"><rect x="6" y="6" width="12" height="12" rx="2"/><path d="M9 3v3M12 3v3M15 3v3M9 18v3M12 18v3M15 18v3M3 9h3M3 12h3M3 15h3M18 9h3M18 12h3M18 15h3"/></svg></span>
                <div style="min-width:0"><div class="tname"><?= e($t['product_name']) ?></div><div class="tbar"><i style="--w:<?= round($t['q'] / $topMax * 100) ?>%"></i></div></div>
                <span class="tsold"><?= (int)$t['q'] ?> sold</span>
            </div>
            <?php endforeach; ?>
        </div>
        <p class="s-sub" style="margin:12px 0 0">Last 30 days</p>
        <?php endif; ?>
    </section>

    <!-- Quick actions -->
    <section class="dcard a-quick reveal" style="--i:<?= ++$i ?>">
        <div class="dhead"><svg class="hi" viewBox="0 0 24 24"><path d="M13 2 4 14h7l-1 8 9-12h-7z"/></svg><h2>Quick Actions</h2></div>
        <div class="qa-grid">
            <a class="qa" href="<?= is_admin() ? 'product_form.php' : 'products.php' ?>"><svg viewBox="0 0 24 24"><path d="M21 8 12 3 3 8v8l9 5 9-5z"/><path d="M3 8l9 5 9-5M12 13v8"/><circle cx="19" cy="19" r="4" fill="#07124a"/><path d="M19 17v4M17 19h4"/></svg>Add New Product</a>
            <a class="qa" href="sales_new.php"><svg viewBox="0 0 24 24"><circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M2 3h3l2.7 12.4a1 1 0 0 0 1 .8h8.6a1 1 0 0 0 1-.8L20 7H6"/></svg>Record Sale</a>
            <a class="qa" href="inventory.php"><svg viewBox="0 0 24 24"><path d="M12 3v11M7.5 10 12 14.5 16.5 10"/><path d="M4 15v4a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-4"/></svg>Stock In</a>
            <a class="qa" href="reports.php"><svg viewBox="0 0 24 24"><path d="M5 20V11M11 20V4M17 20v-7M3 20h18"/></svg>View Reports</a>
        </div>
    </section>

    <!-- Low stock -->
    <section class="dcard a-low reveal" style="--i:<?= ++$i ?>">
        <div class="dhead"><svg class="hi" viewBox="0 0 24 24" style="stroke:#f87171"><path d="M12 3 2 20h20z"/><path d="M12 10v5M12 18v.01"/></svg><h2>Low Stock Alerts</h2><a class="more" href="inventory.php?filter=low">View All</a></div>
        <?php if (!$low): ?><p class="empty">All products are above their minimum stock.</p>
        <?php else: ?>
        <div class="table-wrap"><table>
            <tr><th>Product</th><th class="num">Stock</th><th class="num">Min</th></tr>
            <?php foreach ($low as $p): ?>
            <tr><td><?= e($p['product_name']) ?></td><td class="num"><span class="low-n"><?= (int)$p['quantity'] ?></span></td><td class="num"><?= (int)$p['minimum_stock'] ?></td></tr>
            <?php endforeach; ?>
        </table></div>
        <?php endif; ?>
    </section>

    <!-- Banner -->
    <section class="dcard a-banner reveal" style="--i:<?= ++$i ?>">
        <div class="bico"><svg viewBox="0 0 24 24"><rect x="6" y="6" width="12" height="12" rx="2"/><path d="M9 3v3M12 3v3M15 3v3M9 18v3M12 18v3M15 18v3M3 9h3M3 12h3M3 15h3M18 9h3M18 12h3M18 15h3"/></svg></div>
        <strong>Smart Inventory<br>Better Business</strong>
    </section>
</div>

<script>window.DASH = { periods: <?= json_encode($periods, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?> };</script>
<script src="assets/dashboard.js"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
