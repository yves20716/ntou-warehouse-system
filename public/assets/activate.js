(() => {
    const form = document.getElementById('password-setup-form');
    const token = document.getElementById('setup-token');
    const fragment = location.hash.slice(1);
    if (fragment) {
        const candidate = fragment.startsWith('token=') ? fragment.slice(6) : fragment;
        if (token && /^[a-f0-9]{64}$/i.test(candidate)) token.value = candidate;
        const url = new URL(location.href);
        url.hash = '';
        history.replaceState(history.state, '', url);
    }
    if (!form || !token) return;
    const note = document.getElementById('setup-token-note');
    const submit = document.getElementById('setup-submit');
    const password = document.getElementById('setup-password');
    const confirmation = document.getElementById('setup-confirmation');
    function validateToken() {
        const valid = /^[a-f0-9]{64}$/i.test(token.value);
        note.hidden = valid;
        submit.disabled = !valid;
    }
    function validatePassword() {
        const length = Array.from(password.value).length;
        const size = new TextEncoder().encode(password.value).length;
        const error = length > 0 && length < 12 ? '密碼至少需 12 個字元。'
            : size > 72 ? '密碼過長，請減少字數。' : '';
        password.setCustomValidity(error);
        confirmation.setCustomValidity(confirmation.value && confirmation.value !== password.value ? '兩次輸入的密碼不同。' : '');
    }
    password.addEventListener('input', validatePassword);
    confirmation.addEventListener('input', validatePassword);
    window.addEventListener('pageshow', () => { validateToken(); validatePassword(); });
    validateToken();
    validatePassword();
})();
