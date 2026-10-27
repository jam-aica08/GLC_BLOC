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

    // A table wider than the screen scrolls sideways in its .table-responsive box;
    // that box must be reachable by keyboard (Tab, then arrow keys) and named for
    // screen readers (WCAG 2.1.1). Only boxes that actually scroll get a tab stop.
    function scrollableTables() {
        document.querySelectorAll('.table-responsive').forEach(function (box) {
            var scrolls = box.scrollWidth > box.clientWidth + 1;
            if (scrolls && !box.hasAttribute('tabindex')) {
                var card = box.closest('.card, .modal-content');
                var heading = card && card.querySelector('.card-header, .modal-title, h1, h2, h3');
                box.setAttribute('tabindex', '0');
                box.setAttribute('role', 'region');
                box.setAttribute('aria-label', (heading ? heading.textContent.trim().replace(/\s+/g, ' ') : 'Table') + ' (scrolls sideways)');
                box.dataset.glcScroll = '1';
            } else if (!scrolls && box.dataset.glcScroll) {
                box.removeAttribute('tabindex'); box.removeAttribute('role'); box.removeAttribute('aria-label');
                delete box.dataset.glcScroll;
            }
        });
    }
    scrollableTables();
    window.addEventListener('resize', function () { clearTimeout(scrollableTables.t); scrollableTables.t = setTimeout(scrollableTables, 150); });
    document.addEventListener('shown.bs.modal', scrollableTables);

    // A background request answered "signed out" (401 from AuthFilter: idle
    // limit, password changed, account deactivated) goes to the login page
    // instead of leaving the page showing old figures and retrying.
    var nativeFetch = window.fetch;
    window.fetch = function () {
        return nativeFetch.apply(this, arguments).then(function (res) {
            if (res.status === 401) {
                res.clone().json().then(function (data) {
                    if (data && data.signedOut) window.location.href = '<?= base_url('login') ?>';
                }).catch(function () {});
            }
            return res;
        });
    };

    // Opening the bell marks everything read except important notifications
    // (things still to act on, refusals); the badge then counts only those.
    document.querySelectorAll('.notif-toggle[data-seen-url]').forEach(function (bell) {
        var sent = false;
        // New notifications arrived after the bell was opened: opening it again marks them seen too.
        bell.addEventListener('glc:new-notifications', function () { sent = false; });
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

    // Live bell: while the page is visible, ask every 20 seconds (and at once on
    // returning to the tab) what is new. The count, the dropdown list and the
    // "Mark all as read" link follow, and each new notification pops up for a
    // few seconds ("Loan application to review", with a link). The request is
    // marked X-Background-Poll, so it never keeps an idle sign-in alive.
    document.querySelectorAll('.notif-toggle[data-feed-url]').forEach(function (bell) {
        var menu = bell.parentElement;
        var newest = parseInt(bell.getAttribute('data-newest') || '0', 10);
        var busy = false, timer = null;

        var toasts = document.createElement('div');
        toasts.className = 'toast-container position-fixed bottom-0 end-0 p-3';
        toasts.style.zIndex = 1090;
        document.body.appendChild(toasts);

        function setCount(n) {
            var bellBadge = bell.querySelector('.notif-count');
            if (n > 0 && !bellBadge) {
                bellBadge = document.createElement('span');
                bellBadge.className = 'position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger notif-count';
                bellBadge.style.fontSize = '.65rem';
                bell.appendChild(bellBadge);
            }
            var title = menu.querySelector('.notif-menu .fw-semibold');
            var headBadge = title && title.querySelector('.notif-count');
            if (n > 0 && title && !headBadge) {
                headBadge = document.createElement('span');
                headBadge.className = 'badge bg-danger ms-1 notif-count';
                title.appendChild(headBadge);
            }
            [bellBadge, headBadge].forEach(function (b) { if (!b) return; if (n > 0) b.textContent = n; else b.remove(); });
            var readAll = menu.querySelector('form[action$="/read-all"]');
            if (readAll) readAll.hidden = n < 1;
            bell.setAttribute('aria-label', 'Notifications' + (n > 0 ? ', ' + n + ' unread' : ''));
        }

        function popUp(n) {
            var t = document.createElement('div');
            t.className = 'toast align-items-center border-0 shadow';
            t.setAttribute('role', 'status');
            t.setAttribute('aria-live', 'polite');
            t.setAttribute('aria-atomic', 'true');
            var body = document.createElement('div');
            body.className = 'd-flex';
            var text = document.createElement('div');
            text.className = 'toast-body';
            var strong = document.createElement('div');
            strong.className = 'fw-semibold';
            strong.innerHTML = '<i class="fa-solid fa-bell me-2 glc-icon" aria-hidden="true"></i>';
            strong.appendChild(document.createTextNode(n.title));
            var msg = document.createElement('div');
            msg.className = 'small text-muted mt-1';
            msg.textContent = n.message;
            text.append(strong, msg);
            if (n.link) {
                var a = document.createElement('a');
                a.href = n.link; a.className = 'small d-inline-block mt-1'; a.textContent = 'Open';
                text.appendChild(a);
            }
            var close = document.createElement('button');
            close.type = 'button'; close.className = 'btn-close me-2 m-auto';
            close.setAttribute('data-bs-dismiss', 'toast'); close.setAttribute('aria-label', 'Close');
            body.append(text, close);
            t.appendChild(body);
            toasts.appendChild(t);
            t.addEventListener('hidden.bs.toast', function () { t.remove(); });
            new bootstrap.Toast(t, { delay: 8000 }).show();
        }

        function check() {
            if (busy || document.visibilityState !== 'visible') return;
            busy = true;
            var url = bell.getAttribute('data-feed-url') + '?since=' + newest + '&next=' + encodeURIComponent(bell.getAttribute('data-here') || '');
            fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-Background-Poll': '1' }, cache: 'no-store' })
                .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
                .then(function (data) {
                    // Oldest first, so the newest ends up on top of the stack.
                    (data['new'] || []).slice().reverse().forEach(popUp);
                    newest = Math.max(newest, data.newest || 0);
                    setCount(data.unread || 0);
                    var list = menu.querySelector('.notif-scroll');
                    // Leave the list alone while it is open, so it does not jump under the pointer.
                    if (list && typeof data.html === 'string' && !menu.querySelector('.dropdown-menu.show')) list.innerHTML = data.html;
                    if ((data['new'] || []).length) bell.dispatchEvent(new CustomEvent('glc:new-notifications'));
                })
                .catch(function () {})
                .then(function () { busy = false; });
        }

        function start() { if (!timer) timer = setInterval(check, 20000); }
        function stop() { clearInterval(timer); timer = null; }
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible') { check(); start(); } else { stop(); }
        });
        if (document.visibilityState === 'visible') start();
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
