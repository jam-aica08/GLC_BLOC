<?php /** Pop-up that shows a loan schedule. Any link with class js-schedule, data-loan-id and data-title opens it. */ ?>
<div class="modal fade" id="scheduleModal" tabindex="-1" aria-labelledby="scheduleModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header" style="background:#1a1a2e;">
                <h5 class="modal-title text-white" id="scheduleModalLabel"><i class="fa-solid fa-calendar-days me-2" style="color:#f0a500;"></i><span>Loan Schedule</span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="scheduleModalBody" aria-live="polite"></div>
        </div>
    </div>
</div>

<script>
// Open a loan's schedule in the pop-up instead of a new page. Each schedule is loaded once, then reused.
(function () {
    const modalEl = document.getElementById('scheduleModal');
    const body    = document.getElementById('scheduleModalBody');
    const title   = modalEl.querySelector('.modal-title span');
    const cache   = {};

    document.addEventListener('click', async function (e) {
        const link = e.target.closest('.js-schedule');
        if (!link || typeof bootstrap === 'undefined') return;   // without Bootstrap the link opens the full page
        e.preventDefault();

        title.textContent = link.dataset.title;
        body.innerHTML = '<div class="text-center py-5 text-muted"><span class="spinner-border spinner-border-sm me-2"></span>Loading schedule…</div>';
        bootstrap.Modal.getOrCreateInstance(modalEl).show();

        const id = link.dataset.loanId;
        try {
            if (!cache[id]) {
                const res = await fetch(link.href, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                if (!res.ok) throw new Error(res.status);
                cache[id] = await res.text();
            }
            body.innerHTML = cache[id];
        } catch (err) {
            body.innerHTML = '<div class="alert alert-danger mb-0">Could not load the schedule. <a href="' + link.href + '">Open it as a page</a>.</div>';
        }
    });
})();
</script>
