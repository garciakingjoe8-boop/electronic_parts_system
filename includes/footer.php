    </main>
    <footer class="footer">&copy; <?= date('Y') ?> <?= APP_NAME ?> &middot; Inventory and Sales Management System</footer>
</div>
</div>
<script>
(function () {
    var b = document.body;
    var t = document.getElementById('burger'), s = document.getElementById('scrim');
    if (t) t.addEventListener('click', function () { b.classList.toggle('nav-open'); });
    if (s) s.addEventListener('click', function () { b.classList.remove('nav-open'); });
})();
</script>
</body>
</html>
