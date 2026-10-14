<script>
// Adds a live search box to every card that holds a data table.
document.querySelectorAll('.card').forEach(function (card) {
    const table = card.querySelector('table');
    if (!table || !table.tBodies.length) return;
    const rows = Array.from(table.tBodies[0].rows).filter(r => !r.querySelector('td[colspan]'));
    if (!rows.length) return;

    const input = document.createElement('input');
    input.type = 'search';
    input.className = 'form-control form-control-sm';
    input.placeholder = 'Search…';
    input.setAttribute('aria-label', 'Search this table');
    input.style.maxWidth = '240px';

    const empty = document.createElement('div');
    empty.className = 'text-center text-muted small py-3 d-none';
    empty.textContent = 'No matching records.';
    table.parentNode.after(empty);

    input.addEventListener('input', function () {
        const q = input.value.trim().toLowerCase();
        let shown = 0;
        rows.forEach(function (r) {
            const match = r.textContent.toLowerCase().includes(q);
            r.style.display = match ? '' : 'none';
            if (match) shown++;
        });
        empty.classList.toggle('d-none', shown > 0);
    });

    const header = card.querySelector('.card-header');
    if (header) {
        header.classList.add('d-flex', 'flex-wrap', 'gap-2', 'align-items-center');
        input.classList.add('ms-auto');
        header.appendChild(input);
    } else {
        const wrap = document.createElement('div');
        wrap.className = 'p-2 border-bottom d-flex justify-content-end';
        wrap.appendChild(input);
        card.prepend(wrap);
    }
});
</script>
