const goods = document.querySelector('#in-goods');
const sections = document.querySelector('#in-section');
const inQuantity = document.querySelector('#in-quantity');
const inPreview = document.querySelector('#in-preview');
const formatQuantity = (value) => Number(value).toLocaleString('zh-TW');

function showPreview(element, message, isError = false) {
    element.textContent = message;
    element.classList.toggle('is-error', isError);
}

if (goods && sections && inQuantity && inPreview) {
    const updatePreview = () => {
        const item = goods.selectedOptions[0];
        const section = sections.selectedOptions[0];
        const amount = Number(inQuantity.value);
        inQuantity.setCustomValidity('');
        inQuantity.removeAttribute('aria-invalid');
        if (!goods.value) {
            showPreview(inPreview, '請先選擇貨物，再選擇收貨儲區並填寫數量。');
        } else if (![...sections.options].some((option) => option.value && !option.disabled)) {
            showPreview(inPreview, '這項貨物所屬客戶目前沒有承租儲區，無法登記入庫。', true);
        } else if (!sections.value) {
            showPreview(inPreview, `已選擇「${item.dataset.name}」，請選擇收貨儲區。`);
        } else if (inQuantity.value === '') {
            showPreview(inPreview, `「${item.dataset.name}」將收至 ${section.textContent.trim()}，請填寫入庫數量。`);
        } else if (!/^[0-9]+$/.test(inQuantity.value) || !Number.isInteger(amount) || amount < 1 || amount > 2147483647) {
            const message = '入庫數量請填寫 1 到 2,147,483,647 的整數。';
            inQuantity.setCustomValidity(message);
            inQuantity.setAttribute('aria-invalid', 'true');
            showPreview(inPreview, message, true);
        } else {
            showPreview(inPreview, `將入庫「${item.dataset.name}」${formatQuantity(amount)} ${item.dataset.unit}，收至 ${section.textContent.trim()}。`);
        }
    };
    const updateSections = () => {
        const client = goods.selectedOptions[0]?.dataset.client;
        for (const option of sections.options) {
            const mismatch = Boolean(option.value) && option.dataset.client !== client;
            option.disabled = mismatch;
            option.hidden = mismatch;
        }
        if (sections.selectedOptions[0]?.disabled) sections.value = '';
        sections.options[0].textContent = goods.value ? '選擇儲區' : '請先選擇貨物';
        const noSection = goods.value && ![...sections.options].some((option) => option.value && !option.disabled);
        sections.setCustomValidity(noSection ? '這項貨物所屬客戶目前沒有承租儲區，請先確認儲區設定。' : '');
        updatePreview();
    };
    goods.addEventListener('change', updateSections);
    sections.addEventListener('change', updatePreview);
    inQuantity.addEventListener('input', updatePreview);
    updateSections();
}

const stock = document.querySelector('#out-stock');
const outQuantity = document.querySelector('#out-quantity');
const outPreview = document.querySelector('#out-preview');
if (stock && outQuantity && outPreview) {
    const updatePreview = () => {
        const batch = stock.selectedOptions[0];
        const available = Number(batch?.dataset.quantity);
        const amount = Number(outQuantity.value);
        outQuantity.max = batch?.dataset.quantity || '2147483647';
        outQuantity.setCustomValidity('');
        outQuantity.removeAttribute('aria-invalid');
        if (!stock.value) {
            showPreview(outPreview, '請先選擇庫存批次，再填寫出庫數量。');
            return;
        }
        const unit = batch.dataset.unit;
        const summary = `批號 #${stock.value}「${batch.dataset.name}」，${batch.dataset.location}，目前有 ${formatQuantity(available)} ${unit}。`;
        if (outQuantity.value === '') {
            showPreview(outPreview, `${summary}請填寫出庫數量。`);
        } else if (!/^[0-9]+$/.test(outQuantity.value) || !Number.isInteger(amount) || amount < 1) {
            const message = '出庫數量請填寫大於 0 的整數。';
            outQuantity.setCustomValidity(message);
            outQuantity.setAttribute('aria-invalid', 'true');
            showPreview(outPreview, `${summary}${message}`, true);
        } else if (amount > available) {
            const message = `出庫數量超過庫存 ${formatQuantity(amount - available)} ${unit}，最多可出庫 ${formatQuantity(available)} ${unit}。`;
            outQuantity.setCustomValidity(message);
            outQuantity.setAttribute('aria-invalid', 'true');
            showPreview(outPreview, `${summary}${message}`, true);
        } else {
            showPreview(outPreview, `${summary}本次出庫 ${formatQuantity(amount)} ${unit}，出庫後剩餘 ${formatQuantity(available - amount)} ${unit}。`);
        }
    };
    stock.addEventListener('change', updatePreview);
    outQuantity.addEventListener('input', updatePreview);
    updatePreview();
}
