<script>
// Masks elements marked .js-amount; any .js-amount-toggle button flips them.
(function () {
    const KEY = 'glc-hide-amounts';
    const amounts = document.querySelectorAll('.js-amount');
    const buttons = document.querySelectorAll('.js-amount-toggle');
    if (!amounts.length) return;

    let hidden = false;
    try { hidden = localStorage.getItem(KEY) === '1'; } catch (e) {}

    function render() {
        amounts.forEach(function (el) {
            if (el.dataset.value === undefined) el.dataset.value = el.textContent;
            el.textContent = hidden ? '₱ ••••••' : el.dataset.value;
        });
        buttons.forEach(function (btn) {
            btn.innerHTML = hidden ? '<i class="fa-solid fa-eye"></i>' : '<i class="fa-solid fa-eye-slash"></i>';
            btn.title = hidden ? 'Show amount' : 'Hide amount';
            btn.setAttribute('aria-label', btn.title);
            btn.setAttribute('aria-pressed', hidden ? 'true' : 'false');
        });
    }

    buttons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            hidden = !hidden;
            try { localStorage.setItem(KEY, hidden ? '1' : '0'); } catch (e) {}
            render();
        });
    });
    render();
})();
</script>
