const goods = document.querySelector('#in-goods');
const sections = document.querySelector('#in-section');

function updateSections() {
    const client = goods.selectedOptions[0]?.dataset.client;
    for (const option of sections.options) {
        const mismatch = Boolean(option.value) && option.dataset.client !== client;
        option.disabled = mismatch;
        option.hidden = mismatch;
    }
    if (sections.selectedOptions[0]?.disabled) sections.value = '';
}

if (goods && sections) {
    goods.addEventListener('change', updateSections);
    updateSections();
}

const stock = document.querySelector('#out-stock');
const quantity = document.querySelector('#out-quantity');
if (stock && quantity) {
    const updateLimit = () => {
        quantity.max = stock.selectedOptions[0]?.dataset.quantity || '2147483647';
    };
    stock.addEventListener('change', updateLimit);
    updateLimit();
}
