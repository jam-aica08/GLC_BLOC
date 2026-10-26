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

    // Opening the bell marks everything read except important notifications
    // (things still to act on, refusals); the badge then counts only those.
    document.querySelectorAll('.notif-toggle[data-seen-url]').forEach(function (bell) {
        var sent = false;
        bell.addEventListener('shown.bs.dropdown', function () {
            var menu = bell.parentElement;
            if (sent || !menu.querySelector('.notif-count')) return;
            sent = true;
            var body = new FormData();
            var token = document.querySelector('input[name="<?= csrf_token() ?>"]');
            if (token) body.append(token.name, token.value);
            fetch(bell.getAttribute('data-seen-url'), {
                method: 'POST', body: body, headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
                .then(function (data) {
                    // The token changes after each POST; keep the page's forms working.
                    if (data.csrf) document.querySelectorAll('input[name="<?= csrf_token() ?>"]').forEach(function (i) { i.value = data.csrf; });
                    menu.querySelectorAll('.notif-count').forEach(function (badge) {
                        if (data.unread > 0) badge.textContent = data.unread; else badge.remove();
                    });
                    if (data.unread < 1) {
                        var readAll = menu.querySelector('form[action$="/read-all"]');
                        if (readAll) readAll.hidden = true;
                    }
                    bell.setAttribute('aria-label', 'Notifications' + (data.unread > 0 ? ', ' + data.unread + ' unread' : ''));
                })
                .catch(function () { sent = false; });   // try again next time it opens
        });
    });

    // Label every cell with its column name so wide tables can stack into
    // cards on phones (see .table-stack in glc.css). Opt out with data-no-stack.
    // Pages that swap content in call window.glcStackTables(container) again.
    window.glcStackTables = function (root) {
        (root || document).querySelectorAll('.table-responsive > table').forEach(function (table) {
            if (table.classList.contains('table-stack') || table.hasAttribute('data-no-stack') || !table.tHead) return;
            var head = table.tHead.rows[table.tHead.rows.length - 1];
            if (!head || [].some.call(head.cells, function (c) { return c.colSpan > 1; })) return;
            var labels = [].map.call(head.cells, function (c) { return c.textContent.trim(); });
            function label() {
                [].concat([].slice.call(table.tBodies), table.tFoot ? [table.tFoot] : []).forEach(function (section) {
                    [].forEach.call(section.rows, function (row) {
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
    };
    window.glcStackTables();
})();
</script>
