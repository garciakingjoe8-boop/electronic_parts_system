    </main>
    <div class="footbar">
        <div><b><?= APP_NAME ?></b> &nbsp;|&nbsp; Inventory &amp; Sales Management System</div>
        <div id="clock"><?= date('M j, Y  g:i A') ?></div>
    </div>
</div>
</div>
<script>
window.addEventListener('pageshow', function (e) { if (e.persisted) location.reload(); });
(function () {
    var b = document.body;
    var t = document.getElementById('burger'), s = document.getElementById('scrim');
    if (t) t.addEventListener('click', function () { b.classList.toggle('nav-open'); });
    if (s) s.addEventListener('click', function () { b.classList.remove('nav-open'); });
    var c = document.getElementById('clock');
    if (c) setInterval(function () {
        c.textContent = new Date().toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
    }, 30000);
    document.addEventListener('click', function (e) {
        document.querySelectorAll('details.udrop[open]').forEach(function (d) { if (!d.contains(e.target)) d.removeAttribute('open'); });
    });
})();
</script>
</body>
</html>
