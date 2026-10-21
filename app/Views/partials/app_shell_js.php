<script>
(function () {
    // Off-canvas sidebar for tablets and phones.
    var body = document.body;
    var toggle = document.getElementById('sidebar-toggle');
    var sidebar = document.getElementById('sidebar');

    function setOpen(open) {
        body.classList.toggle('sidebar-open', open);
        if (toggle) toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) {
            var first = sidebar.querySelector('.nav-link.active') || sidebar.querySelector('.nav-link');
            if (first) first.focus({ preventScroll: true });
        } else if (toggle && sidebar.contains(document.activeElement)) {
            toggle.focus();
        }
    }

    if (toggle) toggle.addEventListener('click', function () { setOpen(!body.classList.contains('sidebar-open')); });
    document.querySelectorAll('[data-sidebar-close]').forEach(function (el) {
        el.addEventListener('click', function () { setOpen(false); });
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && body.classList.contains('sidebar-open')) setOpen(false);
    });
    window.matchMedia('(min-width: 992px)').addEventListener('change', function (mq) {
        if (mq.matches) setOpen(false);
    });

    // Label every cell with its column name so wide tables can stack into
    // cards on phones (see .table-stack in glc.css). Opt out with data-no-stack.
    document.querySelectorAll('.table-responsive > table').forEach(function (table) {
        if (table.hasAttribute('data-no-stack') || !table.tHead) return;
        var head = table.tHead.rows[table.tHead.rows.length - 1];
        if (!head || [].some.call(head.cells, function (c) { return c.colSpan > 1; })) return;
        var labels = [].map.call(head.cells, function (c) { return c.textContent.trim(); });
        function label() {
            [].forEach.call(table.tBodies, function (tbody) {
                [].forEach.call(tbody.rows, function (row) {
                    if (row.cells.length === 1 && row.cells[0].colSpan > 1) return;
                    [].forEach.call(row.cells, function (cell, i) {
                        if (!cell.hasAttribute('data-label')) cell.setAttribute('data-label', labels[i] || '');
                    });
                });
            });
        }
        label();
        // Some pages fill rows in later with JavaScript.
        new MutationObserver(label).observe(table, { childList: true, subtree: true });
        table.classList.add('table-stack');
    });
})();
</script>
