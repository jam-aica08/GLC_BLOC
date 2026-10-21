<script>
// Payment window: tick months, type amounts, submit without reloading the page.
(function () {
    const TOKEN = <?= json_encode(csrf_token()) ?>;
    let csrf = <?= json_encode(csrf_hash()) ?>;
    const peso = n => '₱' + Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const checks = form => Array.from(form.querySelectorAll('.js-pay-check'));
    const amountOf = cb => cb.closest('tr').querySelector('.js-pay-amount');

    // Explain what is wrong with an amount, or return '' when it is fine.
    function problem(input) {
        const raw = input.value.trim();
        const value = Number(raw);
        const owed = parseFloat(input.dataset.owed);
        if (raw === '' || !Number.isFinite(value)) return 'Enter an amount in pesos, for example 500.00.';
        if (value <= 0) return 'The amount must be more than ₱0.00.';
        if (Math.abs(Math.round(value * 100) - value * 100) > 1e-6) return 'Use at most two decimal places (centavos).';
        if (value > owed + 0.004) return 'Only ' + peso(owed) + ' is owed for Month ' + input.dataset.month + '.';
        return '';
    }

    function update(form) {
        let total = 0;
        let invalid = false;
        const notes = [];
        checks(form).forEach(function (cb) {
            const input = amountOf(cb);
            const error = document.getElementById(input.getAttribute('aria-describedby'));
            input.disabled = !cb.checked;
            if (!cb.checked) {
                input.value = '';
                input.classList.remove('is-invalid');
                if (error) error.textContent = '';
                return;
            }
            const message = problem(input);
            input.classList.toggle('is-invalid', message !== '');
            input.setAttribute('aria-invalid', message ? 'true' : 'false');
            if (error) error.textContent = message;
            if (message) { invalid = true; return; }
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
        form.querySelector('.js-pay-submit').disabled = invalid || total <= 0 || form.hasAttribute('data-locked');
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
