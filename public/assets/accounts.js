(() => {
    const role = document.getElementById('account-role');
    if (!role) return;
    const bindings = [...document.querySelectorAll('[data-account-binding]')];
    function update() {
        for (const label of bindings) {
            const active = label.dataset.accountBinding === role.value;
            const field = label.querySelector('select');
            label.hidden = !active;
            field.disabled = !active;
            field.required = active;
        }
    }
    role.addEventListener('change', update);
    update();
})();
