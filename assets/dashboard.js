/* Dashboard analytics: animated count-up numbers + switchable sales chart (daily / weekly / monthly / quarterly / annual) */
(function () {
    var D = window.DASH;
    if (!D) return;
    var reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
    var peso = function (n) { return '₱' + Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };

    function animateNum(el, target, money) {
        var fmt = function (v) { return money ? peso(v) : Math.round(v).toLocaleString('en-PH'); };
        if (reduce) { el.textContent = fmt(target); return; }
        var dur = 1100, t0 = performance.now();
        (function tick(t) {
            var p = Math.min(1, (t - t0) / dur), e = 1 - Math.pow(1 - p, 3);
            el.textContent = fmt(target * e);
            if (p < 1) requestAnimationFrame(tick);
        })(t0);
    }
    document.querySelectorAll('[data-count]').forEach(function (el) {
        animateNum(el, parseFloat(el.dataset.count) || 0, el.dataset.money === '1');
    });

    var box = document.getElementById('chart'), tip = document.getElementById('chartTip');
    var bigNum = document.getElementById('bigNum'), bigDelta = document.getElementById('bigDelta');
    var sub = document.getElementById('chartSub'), range = document.getElementById('rangeTotal');
    if (!box) return;
    var current = 'daily', lastW = 0;

    function niceMax(m) {
        if (m <= 0) return 100;
        var p = Math.pow(10, Math.floor(Math.log10(m))), f = m / p;
        return (f <= 1 ? 1 : f <= 2 ? 2 : f <= 2.5 ? 2.5 : f <= 5 ? 5 : 10) * p;
    }
    function short(v) {
        if (v >= 1e6) return '₱' + (v / 1e6).toFixed(v % 1e6 ? 1 : 0).replace(/\.0$/, '') + 'M';
        if (v >= 1e3) return '₱' + (v / 1e3).toFixed(v % 1e3 ? 1 : 0).replace(/\.0$/, '') + 'k';
        return '₱' + (v % 1 ? v.toFixed(1) : v);
    }
    function pct(cur, prev) {
        if (prev > 0) return (cur - prev) / prev * 100;
        return cur > 0 ? null : 0;
    }

    function draw(animate) {
        var P = D.periods[current], S = P.series, n = S.length;
        lastW = box.clientWidth;
        var W = Math.max(320, box.clientWidth), H = W < 520 ? 240 : 300;
        var m = { l: 60, r: 18, t: 16, b: 34 }, iw = W - m.l - m.r, ih = H - m.t - m.b;
        var maxV = niceMax(Math.max.apply(null, S.map(function (s) { return s.v; })));
        var X = function (i) { return n === 1 ? m.l + iw / 2 : m.l + i * iw / (n - 1); };
        var Y = function (v) { return m.t + ih - (v / maxV) * ih; };
        var base = m.t + ih;

        var d = 'M' + X(0) + ',' + Y(S[0].v);
        for (var i = 1; i < n; i++) {
            var x0 = X(i - 1), y0 = Y(S[i - 1].v), x1 = X(i), y1 = Y(S[i].v), mx = (x0 + x1) / 2;
            d += ' C' + mx + ',' + y0 + ' ' + mx + ',' + y1 + ' ' + x1 + ',' + y1;
        }
        var area = d + ' L' + X(n - 1) + ',' + base + ' L' + X(0) + ',' + base + ' Z';

        var g = '';
        for (var k = 0; k <= 4; k++) {
            var gv = maxV * k / 4, gy = Y(gv);
            g += '<line class="c-grid" x1="' + m.l + '" x2="' + (W - m.r) + '" y1="' + gy + '" y2="' + gy + '"/>' +
                 '<text class="c-ylab" x="' + (m.l - 10) + '" y="' + (gy + 4) + '" text-anchor="end">' + short(gv) + '</text>';
        }
        var step = Math.ceil(n / (W < 520 ? 5 : 10)), xl = '';
        S.forEach(function (s, idx) {
            if ((n - 1 - idx) % step === 0) {
                xl += '<text class="c-xlab" x="' + X(idx) + '" y="' + (H - 10) + '" text-anchor="middle">' + s.l + '</text>';
            }
        });
        var dots = '';
        S.forEach(function (s, idx) {
            var last = idx === n - 1;
            if (n > 16 && !last) return;
            if (last) dots += '<circle class="c-ring" cx="' + X(idx) + '" cy="' + Y(s.v) + '" r="5"/>';
            dots += '<circle class="c-dot" cx="' + X(idx) + '" cy="' + Y(s.v) + '" r="' + (last ? 5.5 : 4) + '" style="animation-delay:' + (0.9 + idx * 0.05) + 's"/>';
        });

        box.classList.toggle('static', !animate || reduce);
        box.querySelector('svg') && box.removeChild(box.querySelector('svg'));
        var svg = '<svg viewBox="0 0 ' + W + ' ' + H + '" width="' + W + '" height="' + H + '" role="img" aria-label="Sales chart">' +
            '<defs><linearGradient id="ag" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#ff6a13" stop-opacity=".42"/><stop offset="1" stop-color="#ff6a13" stop-opacity="0"/></linearGradient></defs>' +
            g + xl + '<path class="c-area" d="' + area + '" fill="url(#ag)"/>' +
            '<path class="c-line" pathLength="1" d="' + d + '"/>' +
            '<line class="c-guide" id="cGuide" y1="' + m.t + '" y2="' + base + '"/>' +
            '<circle class="c-hover" id="cHover" r="6"/>' + dots +
            '<rect id="cHit" x="' + m.l + '" y="' + m.t + '" width="' + iw + '" height="' + ih + '" fill="transparent"/></svg>';
        box.insertAdjacentHTML('afterbegin', svg);

        var hit = document.getElementById('cHit'), guide = document.getElementById('cGuide'), hov = document.getElementById('cHover');
        function show(ev) {
            var r = box.getBoundingClientRect(), px = (ev.touches ? ev.touches[0].clientX : ev.clientX) - r.left;
            var idx = n === 1 ? 0 : Math.max(0, Math.min(n - 1, Math.round((px - m.l) / (iw / (n - 1)))));
            var s = S[idx], x = X(idx), y = Y(s.v);
            guide.setAttribute('x1', x); guide.setAttribute('x2', x); guide.style.opacity = 1;
            hov.setAttribute('cx', x); hov.setAttribute('cy', y); hov.style.opacity = 1;
            tip.innerHTML = '<span>' + s.l + '</span><b>' + peso(s.v) + '</b><span>' + s.c + (s.c === 1 ? ' sale' : ' sales') + '</span>';
            tip.classList.add('show');
            var tw = tip.offsetWidth;
            tip.style.left = Math.max(4, Math.min(W - tw - 4, x + 14 > W - tw ? x - tw - 14 : x + 14)) + 'px';
            tip.style.top = Math.max(0, y - 30) + 'px';
        }
        function hide() { guide.style.opacity = 0; hov.style.opacity = 0; tip.classList.remove('show'); }
        hit.addEventListener('mousemove', show); hit.addEventListener('touchmove', show, { passive: true });
        hit.addEventListener('mouseleave', hide); hit.addEventListener('touchend', hide);
    }

    function setPeriod(key) {
        current = key;
        var P = D.periods[key];
        document.querySelectorAll('[data-period]').forEach(function (b) { b.classList.toggle('active', b.dataset.period === key); });
        animateNum(bigNum, P.cur, true);
        var p = pct(P.cur, P.prev);
        bigDelta.className = 'delta ' + (p === null ? 'up' : p > 0 ? 'up' : p < 0 ? 'down' : 'flat');
        bigDelta.innerHTML = (p === null ? '▲ New' : p === 0 ? '— no change' : (p > 0 ? '▲ ' : '▼ ') + Math.abs(p).toFixed(1) + '%') + ' <small>vs ' + P.prevName + '</small>';
        sub.textContent = P.curName + ' · ' + P.range;
        var total = P.series.reduce(function (a, s) { return a + s.v; }, 0);
        range.textContent = 'Total in range: ' + peso(total);
        draw(true);
    }
    document.querySelectorAll('[data-period]').forEach(function (b) {
        b.addEventListener('click', function () { setPeriod(b.dataset.period); });
    });
    var rt;
    if (window.ResizeObserver) new ResizeObserver(function () { clearTimeout(rt); rt = setTimeout(function () { if (box.clientWidth !== lastW) draw(false); }, 120); }).observe(box);
    setPeriod('daily');
})();
