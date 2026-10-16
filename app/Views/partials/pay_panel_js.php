<script>
// Payment window: tick months, type amounts, submit without reloading the page.
(function () {
    const TOKEN = <?= json_encode(csrf_token()) ?>;
    let csrf = <?= json_encode(csrf_hash()) ?>;
    const peso = n => '₱' + Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const checks = form => Array.from(form.querySelectorAll('.js-pay-check'));
    const amountOf = cb => cb.closest('tr').querySelector('.js-pay-amount');

    function update(form) {
        let total = 0;
        const notes = [];
        checks(form).forEach(function (cb) {
            const input = amountOf(cb);
            input.disabled = !cb.checked;
            if (!cb.checked) { input.value = ''; return; }
            const value = parseFloat(input.value) || 0;
            const owed = parseFloat(input.dataset.owed);
            total += value;
            if (value > 0 && value < owed) {
                notes.push('Month ' + input.dataset.month + ': ' + peso(owed - value) + ' left — ' +
                    (input.dataset.nextMonth ? 'moves to Month ' + input.dataset.nextMonth + ' if still unpaid after ' + input.dataset.due + '.'
                                             : 'stays as your remaining balance.'));
            }
        });
        form.querySelector('.js-pay-total').textContent = peso(total);
        form.querySelector('.js-pay-note').textContent = notes.join(' ');
        form.querySelector('.js-pay-submit').disabled = total <= 0;
    }

    document.addEventListener('change', function (e) {
        if (!e.target.classList.contains('js-pay-check')) return;
        const form = e.target.closest('form');
        const list = checks(form);
        const index = list.indexOf(e.target);
        // Months are paid in order: ticking one ticks the earlier ones, unticking clears later ones.
        list.forEach(function (cb, j) {
            if (e.target.checked && j <= index && !cb.checked) cb.checked = true;
            if (!e.target.checked && j > index) cb.checked = false;
        });
        list.forEach(function (cb) {
            const input = amountOf(cb);
            if (cb.checked && !input.value) input.value = input.dataset.owed;
        });
        update(form);
    });

    document.addEventListener('input', function (e) {
        if (e.target.classList.contains('js-pay-amount')) update(e.target.closest('form'));
    });

    document.addEventListener('submit', async function (e) {
        const form = e.target.closest('.js-pay-form');
        const container = form && form.closest('[data-pay-container]');
        if (!container) return;
        e.preventDefault();

        const button = form.querySelector('.js-pay-submit');
        button.disabled = true;
        button.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Submitting…';

        const body = new FormData(form);
        body.set(TOKEN, csrf);

        let data;
        try {
            const res = await fetch(form.action, { method: 'POST', body: body, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            data = await res.json();
        } catch (err) {
            data = { ok: false, message: 'Something went wrong. Please reload the page and try again.', html: null };
        }

        if (data.csrf) csrf = data.csrf;
        const alert = document.createElement('div');
        alert.className = 'alert py-2 ' + (data.ok ? 'alert-success' : 'alert-danger');
        alert.setAttribute('role', 'status');
        alert.textContent = data.message;

        if (data.html) {
            container.innerHTML = data.html;
            const template = document.getElementById('loan-panel-' + container.dataset.loanId);
            if (template) template.innerHTML = data.html;
        } else {
            button.disabled = false;
            button.innerHTML = '<i class="fas fa-paper-plane me-1"></i>Submit Payment';
        }
        container.prepend(alert);
    });
})();
</script>
