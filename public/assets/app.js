const passwordToggle = document.querySelector('.password-toggle');
if (passwordToggle) {
    passwordToggle.addEventListener('click', () => {
        const password = document.getElementById(passwordToggle.getAttribute('aria-controls'));
        const show = password.type === 'password';
        password.type = show ? 'text' : 'password';
        passwordToggle.textContent = show ? '隱藏密碼' : '顯示密碼';
        passwordToggle.setAttribute('aria-pressed', String(show));
    });
}

for (const form of document.querySelectorAll('form[method="post"]')) {
    form.addEventListener('submit', (event) => {
        if (form.dataset.submitting === 'true') {
            event.preventDefault();
            return;
        }
        form.dataset.submitting = 'true';
        form.setAttribute('aria-busy', 'true');
        for (const button of form.querySelectorAll('button[type="submit"], button:not([type])')) {
            button.disabled = true;
        }
    });
}

window.addEventListener('pageshow', () => {
    for (const form of document.querySelectorAll('form[aria-busy]')) {
        delete form.dataset.submitting;
        form.removeAttribute('aria-busy');
        for (const button of form.querySelectorAll('button[type="submit"], button:not([type])')) {
            button.disabled = false;
        }
    }
});

for (const region of document.querySelectorAll('.table-scroll')) {
    const updateScrollHint = () => region.classList.toggle('is-scrollable', region.scrollWidth > region.clientWidth + 1);
    updateScrollHint();
    if ('ResizeObserver' in window) new ResizeObserver(updateScrollHint).observe(region);
}

// A shortcut only when a search field exists, without intercepting typing.
document.addEventListener('keydown', (event) => {
    const target = event.target;
    if (event.key !== '/' || event.ctrlKey || event.metaKey || event.altKey ||
        target.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(target.tagName)) return;
    const search = document.querySelector('input[type="search"]');
    if (search) { event.preventDefault(); search.focus(); }
});
