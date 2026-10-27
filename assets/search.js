/* Easy product finding: live table search with highlighting + searchable product picker for stock-in / adjustments */
(function () {
    'use strict';
    var norm = function (s) { return (s || '').toLowerCase(); };
    var tokens = function (q) { return norm(q).split(/\s+/).filter(Boolean); };
    var esc = function (s) { return s.replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); };
    function mark(text, toks) {                      // highlight every search word inside text (safe HTML)
        if (!toks.length) return esc(text);
        var re = new RegExp('(' + toks.map(function (t) { return t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }).join('|') + ')', 'ig');
        var out = '', last = 0, m;
        while ((m = re.exec(text)) !== null) {
            if (m[0] === '') { re.lastIndex++; continue; }
            out += esc(text.slice(last, m.index)) + '<mark>' + esc(m[0]) + '</mark>';
            last = m.index + m[0].length;
        }
        return out + esc(text.slice(last));
    }

    /* ---------- live table filter ---------- */
    document.querySelectorAll('[data-live]').forEach(function (inp) {
        var table = document.querySelector(inp.dataset.live);
        if (!table) return;
        var rows = Array.prototype.slice.call(table.querySelectorAll('tr[data-s]'));
        var empty = table.querySelector('.no-match');
        var count = inp.dataset.count ? document.querySelector(inp.dataset.count) : null;
        var selects = Array.prototype.slice.call(document.querySelectorAll('[data-filter-for="' + inp.dataset.live + '"]'));
        rows.forEach(function (r) { r.querySelectorAll('.hl-t').forEach(function (c) { c.dataset.o = c.textContent; }); });
        function run() {
            var toks = tokens(inp.value), shown = 0;
            rows.forEach(function (r) {
                var ok = toks.every(function (t) { return r.dataset.s.indexOf(t) !== -1; });
                selects.forEach(function (s) {
                    var v = s.value, a = s.dataset.attr;
                    if (!ok || v === '' || v === 'all' || v === '0') return;
                    if (a === 'st' && v === 'restock') ok = r.dataset.st === 'low' || r.dataset.st === 'out';
                    else ok = r.dataset[a] === v;
                });
                r.hidden = !ok;
                if (ok) { shown++; r.querySelectorAll('.hl-t').forEach(function (c) { c.innerHTML = mark(c.dataset.o, toks); }); }
            });
            if (empty) empty.hidden = shown > 0;
            if (count) count.textContent = shown;
        }
        inp.addEventListener('input', run);
        selects.forEach(function (s) { s.addEventListener('change', run); });
        var clear = document.querySelector('[data-clear-for="' + inp.dataset.live + '"]');
        if (clear) clear.addEventListener('click', function (e) {
            e.preventDefault(); inp.value = ''; selects.forEach(function (s) { s.selectedIndex = 0; }); run(); inp.focus();
        });
        run();
        document.addEventListener('keydown', function (e) {      // press "/" to jump to the search box
            var t = e.target.tagName;
            if (e.key === '/' && t !== 'INPUT' && t !== 'TEXTAREA' && t !== 'SELECT') { e.preventDefault(); inp.focus(); inp.select(); }
        });
    });

    /* ---------- searchable product picker ---------- */
    var PRODUCTS = window.PRODUCTS || [];
    var statusTag = function (p) {
        return p.qty <= 0 ? '<span class="tag tag-out">Out of stock</span>'
            : p.qty <= p.min ? '<span class="tag tag-low">Low</span>' : '<span class="tag tag-ok">In stock</span>';
    };
    document.querySelectorAll('.picker').forEach(function (box) {
        var inp = box.querySelector('.pk-input'), hid = box.querySelector('.pk-id'), list = box.querySelector('.pk-list');
        var card = box.querySelector('.pk-card'), err = box.querySelector('.pk-err');
        var active = -1, shownItems = [], current = null;

        function render() {
            var toks = tokens(inp.value);
            shownItems = PRODUCTS.filter(function (p) { return toks.every(function (t) { return p.s.indexOf(t) !== -1; }); }).slice(0, 8);
            if (!shownItems.length) { list.innerHTML = '<div class="pk-none">No product matches “' + esc(inp.value) + '”.</div>'; }
            else list.innerHTML = shownItems.map(function (p, i) {
                return '<button type="button" class="pk-item' + (i === active ? ' on' : '') + '" data-i="' + i + '">' +
                    '<span class="pk-code">' + mark(p.code, toks) + '</span>' +
                    '<span class="pk-name">' + mark(p.name, toks) + '<small>' + esc(p.cat || 'No category') + '</small></span>' +
                    '<span class="pk-qty">' + p.qty + ' on hand<br>' + statusTag(p) + '</span></button>';
            }).join('');
            list.hidden = false;
        }
        function choose(p) {
            current = p; hid.value = p.id; inp.value = p.name; list.hidden = true; active = -1; if (err) err.hidden = true;
            card.innerHTML = '<div class="pk-card-main"><strong>' + esc(p.name) + '</strong><span>' + esc(p.code) + ' · ' + esc(p.cat || 'No category') + '</span></div>' +
                '<div class="pk-card-num"><b>' + p.qty + '</b><span>on hand</span></div>' +
                '<div class="pk-card-num"><b>' + p.min + '</b><span>minimum</span></div><div>' + statusTag(p) + '</div>';
            card.hidden = false; card.classList.remove('pop'); void card.offsetWidth; card.classList.add('pop');
            box.dispatchEvent(new CustomEvent('picked', { detail: p }));
        }
        function reset() { current = null; hid.value = ''; card.hidden = true; box.dispatchEvent(new CustomEvent('picked', { detail: null })); }
        box._choose = function (id) { var p = PRODUCTS.filter(function (x) { return x.id === id; })[0]; if (p) choose(p); };
        box._current = function () { return current; };

        inp.addEventListener('focus', function () { if (!hid.value) render(); });
        inp.addEventListener('input', function () { if (hid.value) reset(); active = -1; render(); });
        inp.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault(); if (list.hidden) render();
                active = (active + (e.key === 'ArrowDown' ? 1 : -1) + shownItems.length) % Math.max(1, shownItems.length); render();
            } else if (e.key === 'Enter' && !list.hidden && shownItems.length) {
                e.preventDefault(); choose(shownItems[active >= 0 ? active : 0]);
            } else if (e.key === 'Escape') list.hidden = true;
        });
        list.addEventListener('mousedown', function (e) {
            var b = e.target.closest('.pk-item'); if (b) { e.preventDefault(); choose(shownItems[+b.dataset.i]); }
        });
        document.addEventListener('click', function (e) { if (!box.contains(e.target)) list.hidden = true; });
        var form = box.closest('form');
        if (form) form.addEventListener('submit', function (e) {
            if (!hid.value) { e.preventDefault(); if (err) err.hidden = false; inp.focus(); }
        });
    });

    /* ---------- stock-in / adjustment live preview ---------- */
    var pin = document.getElementById('pickerIn'), qty = document.getElementById('qtyIn'), prevIn = document.getElementById('prevIn');
    function updIn() {
        var p = pin && pin._current(), n = parseInt(qty && qty.value, 10);
        if (!prevIn) return;
        if (p && n > 0) { prevIn.hidden = false; prevIn.innerHTML = 'Stock will go from <b>' + p.qty + '</b> to <b class="up">' + (p.qty + n) + '</b>'; }
        else prevIn.hidden = true;
    }
    if (pin) { pin.addEventListener('picked', updIn); if (qty) qty.addEventListener('input', updIn); }
    var padj = document.getElementById('pickerAdj'), cnt = document.getElementById('qtyAdj'), prevAdj = document.getElementById('prevAdj');
    function updAdj() {
        var p = padj && padj._current(), n = parseInt(cnt && cnt.value, 10);
        if (!prevAdj) return;
        if (p && !isNaN(n) && n >= 0) { var d = n - p.qty; prevAdj.hidden = false; prevAdj.innerHTML = 'System says <b>' + p.qty + '</b> · counted <b>' + n + '</b> · difference <b class="' + (d < 0 ? 'down' : 'up') + '">' + (d > 0 ? '+' : '') + d + '</b>'; }
        else prevAdj.hidden = true;
    }
    if (padj) { padj.addEventListener('picked', updAdj); if (cnt) cnt.addEventListener('input', updAdj); }

    /* "+ Stock" buttons in the table, ?product=ID links, and highlighting the product that was just restocked */
    function jumpToIn(id) { if (!pin) return; pin._choose(id); pin.scrollIntoView({ behavior: 'smooth', block: 'center' }); if (qty) setTimeout(function () { qty.focus(); }, 350); }
    document.querySelectorAll('[data-add]').forEach(function (b) { b.addEventListener('click', function () { jumpToIn(+b.dataset.add); }); });
    var qs = new URLSearchParams(location.search);
    if (qs.get('product')) jumpToIn(+qs.get('product'));
    var hl = qs.get('updated') || qs.get('added');
    if (hl) {
        var row = document.getElementById('row-' + hl);
        if (row) { row.hidden = false; row.classList.add('flash'); setTimeout(function () { row.scrollIntoView({ behavior: 'smooth', block: 'center' }); }, 250); }
    }
})();
